<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
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

#[ResolveEntityFromRoute(setter: 'setAccount')]
#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[Route('/accounts/{account}', name: 'api_gryphline_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private const int MAX_BACKUP_UPLOAD_BYTES = 26214400; // 25 MiB

    private GryphlineAccount $account;

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

    protected function getAccount(): GryphlineAccount
    {
        return $this->account;
    }

    public function setAccount(#[MapEntity(id: 'account', message: "Gryphline Account not found.")] GryphlineAccount $account): void
    {
        $this->account = $account;
    }

    protected function checkAccountOwnership(User $user): void
    {
        if ($this->getAccount()->getUser() !== $user) {
            throw $this->createAccessDeniedException("You do not own this Gryphline account.");
        }
    }

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[CurrentUser] User $user): Response
    {
        $this->checkAccountOwnership($user);

        return $this->json($this->getAccount()->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $denormalizer->denormalize($req->getPayload()->all(), GryphlineGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/sync', name: 'pulls_sync', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsSync(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support automated pull synchronization.");
        }

        $bannerTypes = $gameService->getGachaConfig()->getBannerTypes();
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
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        MessageBusInterface $bus,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

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
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        string $operationId,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

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
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo,
        PullItemIconUrlBuilder $iconUrlBuilder,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        Authorization $mercureAuthorization,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $mercureTopic = $operationTracker->getProfileTopic((int) $gameProfile->getId());
        $mercureAuthorization->setCookie($req, [$mercureTopic]);

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups('categories');
        $rarityConfig = $gameService->getRarityConfig();
        $overview = $pullRepo->buildOverview($gameProfile, $bannerGroups, $rarityConfig);

        foreach ($overview['banners'] as &$banner) {
            if (!empty($banner['recent_s_pulls'])) {
                $banner['recent_s_pulls'] = $iconUrlBuilder->enrichPullRows($gameService, $banner['recent_s_pulls']);
            }
        }
        unset($banner);

        return $this->json([
            'profile' => $gameProfile,
            'rarity_config' => $rarityConfig,
            'total_pulls' => $overview['total_pulls'],
            'banners' => $overview['banners'],
            'monthly_timeline' => $overview['monthly_timeline'],
            'available_backup_importers' => $importerManager->getMetadataForGameService($gameService),
            'mercure_topic' => $mercureTopic,
            'active_operation' => $operationTracker->getActiveOperationForProfile((int) $gameProfile->getId()),
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls', name: 'pulls_list', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsList(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo,
        PullItemIconUrlBuilder $iconUrlBuilder
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

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
                $rInt = (int) trim($r);
                if ($rInt > 0) {
                    $rarities[] = $rInt;
                }
            }
        } elseif ($req->query->has('rarity') && $req->query->getInt('rarity') > 0) {
            $rarities[] = $req->query->getInt('rarity');
        }

        $search = $req->query->getString('search');
        $page = max(1, $req->query->getInt('page', 1));
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $result = $pullRepo->findBannerDetailWithPity(
            $gameProfile,
            $bannerGroup,
            $rarityConfig,
            $rarities,
            $search ?: null,
            $page,
            $limit
        );

        $banner = $result['banner'];
        if (!empty($banner['recent_s_pulls'])) {
            $banner['recent_s_pulls'] = $iconUrlBuilder->enrichPullRows($gameService, $banner['recent_s_pulls']);
        }
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
}
