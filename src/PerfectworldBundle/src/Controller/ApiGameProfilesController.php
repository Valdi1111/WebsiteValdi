<?php

namespace App\PerfectworldBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Pull\Card\PullCardQuery;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Message\Profile\ImportPullsBackupMessage;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullCardBuilder;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullStatsCalculator;
use App\PerfectworldBundle\Service\Pull\PullBackupImporterManager;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use App\PerfectworldBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ServiceCollectionInterface;

#[IsGranted('ROLE_USER_PERFECTWORLD', null, 'Access Denied.')]
#[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
#[Route('/accounts/{account}', name: 'api_perfectworld_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private const int MAX_BACKUP_UPLOAD_BYTES = 26214400; // 25 MiB, as the other providers

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[MapEntity(message: 'Account not found.')] PerfectworldAccount $account): Response
    {
        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        DenormalizerInterface $denormalizer,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        $denormalizer->denormalize($req->getPayload()->all(), PerfectworldGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    /**
     * Multi-file backup import: every file is validated before anything is queued.
     */
    #[Route('/gameProfiles/{gameProfile}/pulls/import-backup', name: 'pulls_import_backup', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportBackup(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        PullBackupImporterManager $importerManager,
        PullBackupImportService $importService,
        PullOperationTracker $operationTracker,
        MessageBusInterface $bus,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getPullTrackerGame($gameProfile);

        $importerKey = trim($req->request->getString('importer'));
        $importer = $importerManager->getByKey($importerKey);
        if ($importer === null || !$importerManager->supportsGameService($importer, $gameService)) {
            throw new BadRequestHttpException(sprintf('Importer "%s" is not supported for %s.', $importerKey, $gameService->getGameName()));
        }

        /** @var list<mixed> $files */
        $files = array_values($req->files->all('files'));
        if ($files === [] || count($files) > $importer->getMaxFiles()) {
            throw new BadRequestHttpException(sprintf('Please upload between 1 and %d files.', $importer->getMaxFiles()));
        }
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new BadRequestHttpException('Please upload valid files.');
            }
            if ($file->getSize() > self::MAX_BACKUP_UPLOAD_BYTES) {
                throw new BadRequestHttpException(sprintf('"%s" exceeds the maximum allowed size of 25 MB.', $file->getClientOriginalName()));
            }
            $extension = '.' . strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $importer->getAcceptedExtensions(), true)) {
                throw new BadRequestHttpException(sprintf('Unsupported file extension "%s". Expected: %s.', $extension, implode(', ', $importer->getAcceptedExtensions())));
            }
        }

        if ($operationTracker->hasActiveBackupImport((int) $gameProfile->getId())) {
            throw new ConflictHttpException('A backup import is already in progress for this profile.');
        }

        $tempDir = $this->projectDir . '/var/tmp/perfectworld_pull_imports';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('Failed to create the temporary upload directory.');
        }

        $filePaths = [];
        try {
            $bannerKeys = [];
            foreach ($files as $file) {
                // Server-generated names only: the client file name is never used on disk
                $tempFilename = Uuid::v4()->toRfc4122() . '.' . ltrim($importer->getAcceptedExtensions()[0] ?? '.dat', '.');
                $file->move($tempDir, $tempFilename);
                $filePath = $tempDir . '/' . $tempFilename;
                $filePaths[] = $filePath;

                try {
                    $inspection = $importer->inspect($filePath);
                } catch (\InvalidArgumentException $e) {
                    throw new BadRequestHttpException(sprintf('%s: %s', $file->getClientOriginalName(), $e->getMessage()));
                }

                if ($inspection->gameUid !== null && $inspection->gameUid !== $gameProfile->getGameUid()) {
                    throw new BadRequestHttpException(sprintf('%s: this export belongs to another character (UID %s).', $file->getClientOriginalName(), $inspection->gameUid));
                }
                foreach (array_keys($inspection->banners) as $bannerKey) {
                    if (isset($bannerKeys[$bannerKey])) {
                        throw new BadRequestHttpException(sprintf('Upload only one file per banner (%s appears twice).', $inspection->banners[$bannerKey]['name']));
                    }
                    $bannerKeys[$bannerKey] = true;
                }
            }

            $banners = $importService->buildBannerProgress($importer, $filePaths);
            $operation = $operationTracker->startBackupImport(
                (int) $gameProfile->getId(),
                $user->getId(),
                $importerKey,
                $importer->getLabel(),
                array_sum(array_column($banners, 'total')),
                $banners,
            );

            $bus->dispatch(new ImportPullsBackupMessage(
                operationId: (string) $operation['id'],
                gameProfileId: (int) $gameProfile->getId(),
                importerKey: $importerKey,
                filePaths: $filePaths,
            ));
        } catch (\Throwable $e) {
            foreach ($filePaths as $filePath) {
                @unlink($filePath);
            }
            if (isset($operation)) {
                $operationTracker->failOperation((string) $operation['id'], 'The import could not be queued.');
            }

            throw $e;
        }

        return $this->json([
            'success' => true,
            'message' => 'Pull import queued in background.',
            'operation' => $operation,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/operations/{operationId}', name: 'pulls_operation_status', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOperationStatus(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        string $operationId,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        $operation = $operationTracker->getOperation($operationId);
        if ($operation === null || (int) ($operation['profile_id'] ?? 0) !== (int) $gameProfile->getId()) {
            throw new NotFoundHttpException('Pull operation not found.');
        }

        return $this->json(['operation' => $operation]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/overview', name: 'pulls_overview', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOverview(
        Request $req,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        PerfectworldPullRepository $pullRepository,
        PerfectworldPullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        Authorization $mercureAuthorization,
        NteCatalog $catalog,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getPullTrackerGame($gameProfile);

        $mercureTopic = $operationTracker->getProfileTopic((int) $gameProfile->getId());
        $mercureAuthorization->setCookie($req, [$mercureTopic]);

        $banners = [];
        $monthly = [];
        $totalPulls = 0;
        foreach ($gameService->getGachaConfig()->getBannerGroups('categories') as $bannerKey => $bannerGroup) {
            $computed = $statsCalculator->compute(
                $pullRepository->findBannerRows($gameProfile, (string) $bannerKey),
                $bannerGroup,
                $gameService->getBannerPullLabels((string) $bannerKey),
                $catalog->findPoolName(...),
            );

            $summary = $this->enrichSummaryIcons($iconUrlBuilder, $gameService, $computed['summary']);
            $banners[] = $summary;
            $totalPulls += $summary['total_pulls'];

            foreach ($computed['monthly'] as $month => $counts) {
                $monthly[$month] ??= ['month' => $month, 'total' => 0, 's_count' => 0, 'a_count' => 0];
                $monthly[$month]['total'] += $counts['total'];
                $monthly[$month]['s_count'] += $counts['s_count'];
                $monthly[$month]['a_count'] += $counts['a_count'];
            }
        }
        ksort($monthly);

        return $this->json([
            'profile' => $gameProfile,
            'rarity_config' => $gameService->getRarityConfig(),
            'total_pulls' => $totalPulls,
            'banners' => $banners,
            'monthly_timeline' => array_values($monthly),
            'available_backup_importers' => $importerManager->getMetadataForGameService($gameService),
            'mercure_topic' => $mercureTopic,
            'active_operation' => $operationTracker->getActiveOperationForProfile((int) $gameProfile->getId()),
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls', name: 'pulls_list', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsList(
        Request $req,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        PerfectworldPullRepository $pullRepository,
        PerfectworldPullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder,
        NteCatalog $catalog,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getPullTrackerGame($gameProfile);

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups('categories');
        $bannerKey = $req->query->getString('banner', $req->query->getString('category'));
        if (!isset($bannerGroups[$bannerKey])) {
            $bannerKey = (string) array_key_first($bannerGroups);
        }

        // Non-negative integers only: 0 selects the filler rows (unknown pulls)
        $rarities = [];
        foreach (explode(',', $req->query->getString('rarities')) as $rarity) {
            $rarity = trim($rarity);
            if (ctype_digit($rarity)) {
                $rarities[] = (int) $rarity;
            }
        }
        $page = max(1, $req->query->getInt('page', 1));
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $computed = $statsCalculator->compute(
            $pullRepository->findBannerRows($gameProfile, $bannerKey),
            $bannerGroups[$bannerKey],
            $gameService->getBannerPullLabels($bannerKey),
            $catalog->findPoolName(...),
        );
        $result = $statsCalculator->filterAndPaginate($computed['items'], $rarities, $req->query->getString('search') ?: null, $page, $limit);

        $banner = $this->enrichSummaryIcons($iconUrlBuilder, $gameService, $computed['summary']);

        return $this->json([
            'banner' => $banner,
            'rarity_config' => $gameService->getRarityConfig(),
            'items' => $iconUrlBuilder->enrichPullRows($gameService, $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * S items a manual S of the banner can be (catalog only: NTE manual pulls need a catalog id).
     */
    /**
     * The shareable pulls card (PNG download): one banner, or every banner with pulls; see PullCardQuery.
     */
    #[Route('/gameProfiles/{gameProfile}/pulls/card', name: 'pulls_card', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsCard(
        #[CurrentUser] User $user,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        PerfectworldPullCardBuilder $cardBuilder,
        PullCardRenderer $cardRenderer,
        PullCardLimiter $cardLimiter,
        #[MapQueryString]
        PullCardQuery $query = new PullCardQuery(),
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $cardLimiter->consume($user);

        try {
            $card = $cardBuilder->build($gameProfile, $query->banner, $query->all, $query->player);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $cardRenderer->renderResponse($card);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/manual/suggestions', name: 'pulls_manual_suggestions', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsManualSuggestions(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        ManualPullService $manualPullService,
        PullItemIconUrlBuilder $iconUrlBuilder,
        #[MapQueryParameter] string $banner = '',
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);

        return $this->json([
            'items' => $iconUrlBuilder->enrichPullRows($gameService, $manualPullService->getSuggestions($gameService, $banner)),
            'allow_free_text' => false,
            'item_types' => [],
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/manual', name: 'pulls_manual_create', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsManualCreate(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        #[MapRequestPayload] CreateManualPullRequest $payload,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);

        $pull = $manualPullService->createManualS($gameProfile, $gameService, $payload);

        return $this->json(['success' => true, 'pull_id' => $pull->getId()], Response::HTTP_CREATED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/{pull}/declared-pity', name: 'pulls_declared_pity', requirements: ['gameProfile' => '\d+', 'pull' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilePullsDeclaredPity(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        #[MapEntity(message: 'Pull not found.')] PerfectworldPull $pull,
        #[MapRequestPayload] DeclarePityRequest $payload,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);
        $this->assertPullOfProfile($gameProfile, $pull);

        $manualPullService->declarePity($gameProfile, $gameService, $pull, $payload->pity);

        return $this->json(['success' => true]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/{pull}', name: 'pulls_manual_delete', requirements: ['gameProfile' => '\d+', 'pull' => '\d+'], methods: ['DELETE'])]
    public function apiGameProfilePullsManualDelete(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapEntity(message: 'Game profile not found.')] PerfectworldGameProfile $gameProfile,
        #[MapEntity(message: 'Pull not found.')] PerfectworldPull $pull,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);
        $this->assertPullOfProfile($gameProfile, $pull);

        $manualPullService->deleteManual($gameProfile, $gameService, $pull);

        return $this->json(['success' => true]);
    }

    /**
     * Icons of the S pulls of a banner summary (recent_s_pulls and oldest_s) and of the featured items of its
     * rotations (pools[].featured; the standard board has no rotations).
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function enrichSummaryIcons(PullItemIconUrlBuilder $iconUrlBuilder, GameInterface $gameService, array $summary): array
    {
        $summary['recent_s_pulls'] = $iconUrlBuilder->enrichPullRows($gameService, $summary['recent_s_pulls']);
        if (isset($summary['oldest_s'])) {
            $summary['oldest_s'] = $iconUrlBuilder->enrichPullRows($gameService, [$summary['oldest_s']])[0];
        }
        foreach ($summary['pools'] ?? [] as $index => $pool) {
            if (!empty($pool['featured'])) {
                $summary['pools'][$index]['featured'] = $iconUrlBuilder->enrichPullRows($gameService, $pool['featured']);
            }
        }

        return $summary;
    }

    private function assertProfileOfAccount(PerfectworldAccount $account, PerfectworldGameProfile $gameProfile): void
    {
        if ($gameProfile->getAccount() !== $account) {
            throw new BadRequestHttpException('Game profile does not belong to this account.');
        }
    }

    private function getPullTrackerGame(PerfectworldGameProfile $gameProfile): GameInterface&HasPullTrackerInterface
    {
        $gameId = (int) $gameProfile->getGameId();
        $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;
        if (!$gameService instanceof GameInterface || !$gameService instanceof HasPullTrackerInterface) {
            throw new BadRequestHttpException('This game profile does not support pull tracking.');
        }

        return $gameService;
    }

    private function getManualPullsGame(PerfectworldGameProfile $gameProfile): GameInterface&HasPullTrackerInterface
    {
        $gameService = $this->getPullTrackerGame($gameProfile);
        if (!$gameService instanceof HasManualPullsInterface) {
            throw new BadRequestHttpException('This game does not support manual pulls.');
        }

        return $gameService;
    }

    private function assertPullOfProfile(PerfectworldGameProfile $gameProfile, PerfectworldPull $pull): void
    {
        if ($pull->getGameProfile() !== $gameProfile) {
            throw new NotFoundHttpException('Pull not found.');
        }
    }
}
