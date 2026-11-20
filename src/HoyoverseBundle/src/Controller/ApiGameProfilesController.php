<?php

namespace App\HoyoverseBundle\Controller;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\CoreBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Repository\HoyoverseDiaryEntryRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
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
#[IsGranted('ROLE_USER_HOYOVERSE', null, 'Access Denied.')]
#[Route('/api/accounts/{account}', name: 'api_hoyoverse_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private const int MAX_BACKUP_UPLOAD_BYTES = 26214400; // 25 MiB

    private HoyoverseAccount $account;

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameId,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    protected function getAccount(): HoyoverseAccount
    {
        return $this->account;
    }

    public function setAccount(#[MapEntity(id: 'account', message: "Hoyoverse Account not found.")] HoyoverseAccount $account): void
    {
        $this->account = $account;
    }

    protected function checkAccountOwnership(User $user): void
    {
        if ($this->getAccount()->getUser() !== $user) {
            throw $this->createAccessDeniedException("You do not own this Hoyoverse account.");
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
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile
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
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $denormalizer->denormalize($req->getPayload()->all(), HoyoverseGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/diary/meta', name: 'diary_meta', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfileDiaryMeta(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $currencies = [];
        if ($this->locatorByGameId->has($gameProfile->getGameId())) {
            $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
            if ($gameService instanceof HasDiaryInterface) {
                foreach ($gameService->getDiaryCurrencies() as $curr) {
                    $currencies[] = [
                        'value' => $curr->value,
                        'label' => $curr->getLabel(),
                        'api_type' => $curr->getApiType(),
                    ];
                }
            }
        }

        $periods = $diaryRepo->findDistinctPeriods($gameProfile);

        return $this->json([
            'profile' => [
                'id' => $gameProfile->getId(),
                'nickname' => $gameProfile->getNickname(),
                'game_name' => $gameProfile->getGameName(),
                'game_uid' => $gameProfile->getGameUid(),
                'sync_diary' => $gameProfile->isSyncDiary(),
            ],
            'currencies' => $currencies,
            'periods' => $periods,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/diary/summary', name: 'diary_summary', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfileDiarySummary(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $period = $req->query->getString('period');
        $currencyRaw = $req->query->getString('currency');

        $currency = GameDiaryCurrency::tryFrom($currencyRaw);
        if (!$currency) {
            throw new BadRequestHttpException("Invalid currency supplied.");
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new BadRequestHttpException("Period must follow YYYY-MM format.");
        }

        $currentTotal = $diaryRepo->getTotalForPeriod($gameProfile, $period, $currency);

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $period . '-01');
        $prevPeriod = $date ? $date->modify('-1 month')->format('Y-m') : null;
        $prevTotal = $prevPeriod ? $diaryRepo->getTotalForPeriod($gameProfile, $prevPeriod, $currency) : 0;

        $percentageDiff = null;
        if ($prevTotal > 0) {
            $percentageDiff = round((($currentTotal - $prevTotal) / $prevTotal) * 100, 1);
        } elseif ($currentTotal > 0 && $prevTotal === 0) {
            $percentageDiff = 100.0;
        }

        $actionBreakdown = $diaryRepo->getActionBreakdown($gameProfile, $period, $currency);
        $entries = $diaryRepo->getEntriesForDailyTrend($gameProfile, $period, $currency);
        $dailyAggregates = [];
        $runningTotal = 0;

        foreach ($entries as $entry) {
            /** @var \DateTimeInterface $recordedAt */
            $recordedAt = $entry['recordedAt'];
            $dayKey = $recordedAt->format('Y-m-d');

            if (!isset($dailyAggregates[$dayKey])) {
                $dailyAggregates[$dayKey] = 0;
            }
            $dailyAggregates[$dayKey] += (int) $entry['amount'];
        }

        $dailyTimeline = [];
        foreach ($dailyAggregates as $dateKey => $amount) {
            $runningTotal += $amount;
            $dailyTimeline[] = [
                'date' => $dateKey,
                'amount' => $amount,
                'cumulative' => $runningTotal,
            ];
        }

        return $this->json([
            'period' => $period,
            'currency' => $currency->value,
            'current_total' => $currentTotal,
            'previous_period' => $prevPeriod,
            'previous_total' => $prevTotal,
            'percentage_diff' => $percentageDiff,
            'breakdown' => $actionBreakdown,
            'daily_timeline' => $dailyTimeline,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/diary/entries', name: 'diary_entries', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfileDiaryEntries(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $period = $req->query->getString('period');
        $currencyRaw = $req->query->getString('currency');
        $actionFilter = $req->query->has('filter') ? $req->query->getString('filter') : null;
        $dateFilter = $req->query->has('date') ? $req->query->getString('date') : null;
        $page = max(1, $req->query->getInt('page', 1));
        // Default limit aligned to 15
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $currency = GameDiaryCurrency::tryFrom($currencyRaw);
        if (!$currency) {
            throw new BadRequestHttpException("Invalid currency supplied.");
        }

        $result = $diaryRepo->findPaginated(
            $gameProfile,
            $period,
            $currency,
            $actionFilter,
            $dateFilter,
            $page,
            $limit
        );

        return $this->json([
            'items' => array_map(static fn($e) => [
                'id' => $e->getId(),
                'recorded_at' => $e->getRecordedAt()?->format('Y-m-d H:i:s'),
                'amount' => $e->getAmount(),
                'action_name' => $e->getActionName(),
            ], $result['items']),
            'total' => $result['total'],
            'filtered_amount' => $result['filteredTotalAmount'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/sync', name: 'pulls_sync', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsSync(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasStokenPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support automated SToken pull synchronization.");
        }

        $bannerTypes = $gameService->getGachaConfig()->getBannerTypes();
        $operation = $operationTracker->startSyncOperation(
            (int) $gameProfile->getId(),
            $user->getId(),
            PullOperationKind::STOKEN_SYNC,
            $bannerTypes
        );

        $delaySeconds = 0;
        foreach (array_keys($bannerTypes) as $gachaType) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $gameProfile->getId(),
                    (string) $gachaType,
                    '0',
                    null,
                    [],
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

    #[Route('/gameProfiles/{gameProfile}/pulls/import-powershell', name: 'pulls_import_powershell', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportPowershell(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPowershellPullImportInterface || !$gameService instanceof HasPullTrackerInterface) {
            throw new BadRequestHttpException("This game profile does not support PowerShell pull import.");
        }

        $payload = json_decode($req->getContent(), true);
        $url = trim((string) ($payload['url'] ?? ''));

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new BadRequestHttpException("Invalid URL provided.");
        }

        $parsedUrl = parse_url($url);
        $extractedParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $extractedParams);
        }

        if (empty($extractedParams['authkey'])) {
            throw new BadRequestHttpException("The provided URL does not contain an authkey parameter.");
        }

        if (!empty($extractedParams['game_biz']) && $extractedParams['game_biz'] !== $gameProfile->getGameBiz()) {
            throw new BadRequestHttpException(sprintf(
                'The provided URL belongs to "%s" and does not match this profile ("%s").',
                (string) $extractedParams['game_biz'],
                (string) $gameProfile->getGameBiz()
            ));
        }

        $authkey = (string) $extractedParams['authkey'];
        unset($extractedParams['authkey'], $extractedParams['gacha_type'], $extractedParams['real_gacha_type'], $extractedParams['page'], $extractedParams['size'], $extractedParams['end_id']);

        $bannerTypes = $gameService->getGachaConfig()->getBannerTypes();
        $operation = $operationTracker->startSyncOperation(
            (int) $gameProfile->getId(),
            $user->getId(),
            PullOperationKind::POWERSHELL_IMPORT,
            $bannerTypes
        );

        $delaySeconds = 0;
        foreach (array_keys($bannerTypes) as $gachaType) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $gameProfile->getId(),
                    (string) $gachaType,
                    '0',
                    $authkey,
                    $extractedParams,
                    (string) $operation['id']
                ),
                $stamps
            );
            $delaySeconds += 15;
        }

        return $this->json([
            'success' => true,
            'message' => 'PowerShell pulls import queued in background.',
            'operation' => $operation,
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/import-backup', name: 'pulls_import_backup', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportBackup(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        MessageBusInterface $bus,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPullTrackerInterface) {
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

        $tempDir = $this->projectDir . '/var/tmp/hoyoverse_pull_imports';
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
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
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
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo,
        PullItemIconUrlBuilder $iconUrlBuilder,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        Authorization $mercureAuthorization,
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPullTrackerInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $mercureTopic = $operationTracker->getProfileTopic((int) $gameProfile->getId());
        $mercureAuthorization->setCookie($req, [$mercureTopic]);

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups();
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
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo,
        PullItemIconUrlBuilder $iconUrlBuilder
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPullTrackerInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups();
        $rarityConfig = $gameService->getRarityConfig();

        $bannerKey = $req->query->getString('banner', $req->query->getString('gacha_type'));
        if ($bannerKey === '' || !isset($bannerGroups[$bannerKey])) {
            $firstKey = array_key_first($bannerGroups);
            $bannerGroup = $firstKey !== null ? $bannerGroups[$firstKey] : [
                'key' => 'all',
                'name' => 'All Banners',
                'gacha_types' => [],
                's_max_pity' => 90,
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
