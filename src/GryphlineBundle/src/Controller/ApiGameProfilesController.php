<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Pull\Card\PullCardQuery;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\Pull\GryphlinePullCardBuilder;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullBackupImporterManager;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
#[Route('/accounts/{account}', name: 'api_gryphline_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private const int MAX_BACKUP_UPLOAD_BYTES = 26214400; // 25 MiB

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameBiz,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account): Response
    {
        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        $denormalizer->denormalize($req->getPayload()->all(), GryphlineGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/sync', name: 'pulls_sync', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsSync(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support automated pull synchronization.");
        }

        $bannerTypes = $gameService->getSyncStreams();
        $operation = $operationTracker->startSyncOperation(
            (int) $gameProfile->getId(),
            $user->getId(),
            PullOperationKind::AUTO_SYNC,
            $bannerTypes
        );

        $delaySeconds = 0;
        foreach (array_keys($bannerTypes) as $category) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $gameProfile->getId(),
                    (string) $category,
                    null,
                    null,
                    (string) $operation['id']
                ),
                $stamps
            );
            $delaySeconds += 15;
        }

        return $this->json([
            'success' => true,
            'message' => 'Pulls synchronization queued in background.',
            'operation' => $operation,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/import-backup', name: 'pulls_import_backup', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportBackup(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        MessageBusInterface $bus,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $importerKey = trim($req->request->getString('importer'));
        if ($importerKey === '') {
            throw new BadRequestHttpException("Missing 'importer' parameter.");
        }

        $importer = $importerManager->getByKey($importerKey);
        if ($importer === null || !$importerManager->supportsGameService($importer, $gameService)) {
            throw new BadRequestHttpException(sprintf(
                'Importer "%s" is not supported for %s.',
                $importerKey,
                $gameService->getGameName()
            ));
        }

        /** @var UploadedFile|null $file */
        $file = $req->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new BadRequestHttpException("Please upload a valid backup file.");
        }

        if ($file->getSize() !== false && $file->getSize() > self::MAX_BACKUP_UPLOAD_BYTES) {
            throw new BadRequestHttpException("Backup file exceeds the maximum allowed size of 25 MB.");
        }

        $originalExt = '.' . strtolower($file->getClientOriginalExtension());
        if ($originalExt !== '.' && !in_array($originalExt, $importer->getAcceptedExtensions(), true)) {
            throw new BadRequestHttpException(sprintf(
                'Unsupported file extension "%s". Expected: %s.',
                $originalExt,
                implode(', ', $importer->getAcceptedExtensions())
            ));
        }

        if ($operationTracker->hasActiveBackupImport((int) $gameProfile->getId())) {
            throw new ConflictHttpException("A backup import is already in progress for this profile.");
        }

        $tempDir = $this->projectDir . '/var/tmp/gryphline_pull_imports';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('Failed to create temporary upload directory.');
        }

        $safeExt = ltrim($importer->getAcceptedExtensions()[0] ?? '.dat', '.');
        $tempFilename = sprintf('%s.%s', Uuid::v4()->toRfc4122(), $safeExt);
        $file->move($tempDir, $tempFilename);
        $tempFilePath = $tempDir . '/' . $tempFilename;

        $validation = $importer->validate($tempFilePath);
        if (!$validation->valid) {
            @unlink($tempFilePath);
            throw new BadRequestHttpException($validation->error ?? 'Invalid backup file format.');
        }

        $operation = $operationTracker->startBackupImport(
            (int) $gameProfile->getId(),
            $user->getId(),
            $importerKey,
            $importer->getLabel(),
            $validation->estimatedCount
        );

        $bus->dispatch(new ImportPullsBackupMessage(
            operationId: (string) $operation['id'],
            gameProfileId: (int) $gameProfile->getId(),
            importerKey: $importerKey,
            filePath: $tempFilePath,
            deleteFileAfter: true,
        ));

        return $this->json([
            'success' => true,
            'message' => 'Backup pulls import queued in background.',
            'operation' => $operation,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/operations/{operationId}', name: 'pulls_operation_status', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOperationStatus(
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        string $operationId,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        $operation = $operationTracker->getOperation($operationId);
        if ($operation === null || (int) ($operation['profile_id'] ?? 0) !== (int) $gameProfile->getId()) {
            throw new NotFoundHttpException("Pull operation not found.");
        }

        return $this->json([
            'operation' => $operation,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/overview', name: 'pulls_overview', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOverview(
        Request $req,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo,
        GryphlinePullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        Authorization $mercureAuthorization,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $mercureTopic = $operationTracker->getProfileTopic((int) $gameProfile->getId());
        $mercureAuthorization->setCookie($req, [$mercureTopic]);

        $rarityConfig = $gameService->getRarityConfig();
        $groupedRows = $statsCalculator->groupRowsByBanner(
            $pullRepo->findProfileRows($gameProfile),
            $gameService->getGachaConfig()->getBannerGroups('categories'),
        );

        $banners = [];
        $monthly = [];
        $totalPulls = 0;
        foreach ($groupedRows as ['group' => $bannerGroup, 'rows' => $rows]) {
            $computed = $statsCalculator->compute($rows, $bannerGroup, $rarityConfig);

            $summary = $this->enrichBannerIcons($iconUrlBuilder, $gameService, $computed['summary']);
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
            'rarity_config' => $rarityConfig,
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
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo,
        GryphlinePullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups('categories');
        $rarityConfig = $gameService->getRarityConfig();

        $bannerKey = $req->query->getString('banner', $req->query->getString('category'));
        if ($bannerKey === '' || !isset($bannerGroups[$bannerKey])) {
            $firstKey = array_key_first($bannerGroups);
            $bannerGroup = $firstKey !== null ? $bannerGroups[$firstKey] : [
                'key' => 'char',
                'name' => 'Character Headhunting',
                'categories' => ['char'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ];
        } else {
            $bannerGroup = $bannerGroups[$bannerKey];
        }

        $raritiesParam = $req->query->getString('rarities');
        $rarities = [];
        if ($raritiesParam !== '') {
            foreach (explode(',', $raritiesParam) as $r) {
                $r = trim($r);
                // Digits only: 0 selects the unknown (filler) pulls; empty, negative and other values are skipped
                if ($r !== '' && ctype_digit($r)) {
                    $rarities[] = (int) $r;
                }
            }
        } elseif ($req->query->has('rarity') && $req->query->getInt('rarity') > 0) {
            $rarities[] = $req->query->getInt('rarity');
        }

        $search = $req->query->getString('search');
        $page = max(1, $req->query->getInt('page', 1));
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $computed = $statsCalculator->compute(
            $pullRepo->findBannerRows($gameProfile, $bannerGroup['categories']),
            $bannerGroup,
            $rarityConfig,
        );
        $result = $statsCalculator->filterAndPaginate($computed['items'], $rarities, $search ?: null, $page, $limit);

        $banner = $this->enrichBannerIcons($iconUrlBuilder, $gameService, $computed['summary']);
        // Bonus entries without an item (e.g. the Headhunting Dossier) get no image from the builder
        $items = $iconUrlBuilder->enrichPullRows($gameService, $result['items']);

        return $this->json([
            'banner' => $banner,
            'rarity_config' => $rarityConfig,
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * The shareable pulls card (PNG download): one banner, or every banner with pulls; see PullCardQuery.
     */
    #[Route('/gameProfiles/{gameProfile}/pulls/card', name: 'pulls_card', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsCard(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullCardBuilder $cardBuilder,
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
        Request $req,
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        ManualPullService $manualPullService,
        PullItemIconUrlBuilder $iconUrlBuilder,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);

        $items = $manualPullService->getSuggestions($gameService, $req->query->getString('banner'));

        return $this->json([
            'items' => $iconUrlBuilder->enrichPullRows($gameService, $items),
            // The item type is implied by the banner (operators or weapons), so free text needs no type
            'allow_free_text' => true,
            'item_types' => [],
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/manual', name: 'pulls_manual_create', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsManualCreate(
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        #[MapRequestPayload] CreateManualPullRequest $request,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);

        $pull = $manualPullService->createManualS($gameProfile, $gameService, $request);

        return $this->json(['success' => true, 'pull_id' => $pull->getId()], Response::HTTP_CREATED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/{pull}/declared-pity', name: 'pulls_declared_pity', requirements: ['gameProfile' => '\d+', 'pull' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilePullsDeclaredPity(
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        #[MapEntity(message: "Pull not found.")] GryphlinePull $pull,
        #[MapRequestPayload] DeclarePityRequest $request,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $this->assertPullOfProfile($gameProfile, $pull);
        $gameService = $this->getManualPullsGame($gameProfile);

        $manualPullService->declarePity($gameProfile, $gameService, $pull, $request->pity);

        return $this->json(['success' => true]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/{pull}', name: 'pulls_manual_delete', requirements: ['gameProfile' => '\d+', 'pull' => '\d+'], methods: ['DELETE'])]
    public function apiGameProfilePullsManualDelete(
        #[MapEntity(message: "Gryphline Account not found.")] GryphlineAccount $account,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        #[MapEntity(message: "Pull not found.")] GryphlinePull $pull,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $this->assertPullOfProfile($gameProfile, $pull);
        $gameService = $this->getManualPullsGame($gameProfile);

        $manualPullService->deleteManual($gameProfile, $gameService, $pull);

        return $this->json(['success' => true]);
    }

    /**
     * Adds icon_url to the S items of a banner summary (recent_s_pulls, oldest_s) and to the rate-up items
     * of each banner in its per-banner breakdown (pools[].featured).
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function enrichBannerIcons(PullItemIconUrlBuilder $iconUrlBuilder, object $gameService, array $summary): array
    {
        if (!empty($summary['recent_s_pulls'])) {
            $summary['recent_s_pulls'] = $iconUrlBuilder->enrichPullRows($gameService, $summary['recent_s_pulls']);
        }
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

    /**
     * Game service of a profile whose pull tracker accepts manual pulls (400 otherwise).
     */
    private function getManualPullsGame(GryphlineGameProfile $gameProfile): HasAutoPullImportInterface
    {
        if (!$this->locatorByGameBiz->has((string) $gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get((string) $gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface || !$gameService instanceof HasManualPullsInterface) {
            throw new BadRequestHttpException("This game profile does not support manual pulls.");
        }

        return $gameService;
    }

    private function assertPullOfProfile(GryphlineGameProfile $gameProfile, GryphlinePull $pull): void
    {
        if ($pull->getGameProfile()?->getId() !== $gameProfile->getId()) {
            throw new NotFoundHttpException("Pull not found.");
        }
    }

    private function assertProfileOfAccount(GryphlineAccount $account, GryphlineGameProfile $gameProfile): void
    {
        if ($gameProfile->getAccount() !== $account) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }
    }
}
