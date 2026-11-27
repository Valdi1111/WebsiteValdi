<?php

namespace App\HoyoverseBundle\Controller;

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
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Repository\HoyoverseDiaryEntryRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\Pull\HoyoversePullCardBuilder;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\Pull\HoyoverseRateUpLookupFactory;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\ManualPullSuggestionProvider;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportLauncher;
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

#[IsGranted('ROLE_USER_HOYOVERSE', null, 'Access Denied.')]
#[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
#[Route('/accounts/{account}', name: 'api_hoyoverse_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private const int MAX_BACKUP_UPLOAD_BYTES = 26214400; // 25 MiB

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

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account): Response
    {
        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        $denormalizer->denormalize($req->getPayload()->all(), HoyoverseGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/diary/meta', name: 'diary_meta', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfileDiaryMeta(
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoverseDiaryEntryRepository $diaryRepo
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        MessageBusInterface $bus,
        PullOperationTracker $operationTracker,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        MessageBusInterface $bus,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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

    /**
     * Queues the import of the official S-rank history (e.g. the Zenless "Signal Search" statistics) into the missing
     * pulls of the profile. About 15 HoYoLAB requests: always in the background, tracked like the other pull operations.
     */
    #[Route('/gameProfiles/{gameProfile}/pulls/import-s-rank-history', name: 'pulls_import_s_rank_history', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportSRankHistory(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        SRankHistoryImportLauncher $launcher,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        // 400 without the capability or with an unusable cookie, 409 while one runs (shared with the console command)
        $operation = $launcher->queue($gameProfile, $user->getId());

        return $this->json([
            'success' => true,
            'message' => 'Official S-rank history import queued in background.',
            'operation' => $operation,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/operations/{operationId}', name: 'pulls_operation_status', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOperationStatus(
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo,
        HoyoversePullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder,
        PullBackupImporterManager $importerManager,
        PullOperationTracker $operationTracker,
        Authorization $mercureAuthorization,
        HoyoverseServerTimezoneResolver $timezoneResolver,
        HoyoverseRateUpLookupFactory $rateUpLookups,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPullTrackerInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $mercureTopic = $operationTracker->getProfileTopic((int) $gameProfile->getId());
        $mercureAuthorization->setCookie($req, [$mercureTopic]);

        $rarityConfig = $gameService->getRarityConfig();
        $now = $timezoneResolver->getServerWallClockNow($gameProfile);
        $rateUp = $rateUpLookups->forProfile($gameProfile);
        // One query for the whole profile: gacha types missing from the config become dynamic groups
        $groupedRows = $statsCalculator->groupRowsByBanner(
            $pullRepo->findProfileRows($gameProfile),
            $gameService->getGachaConfig()->getBannerGroups(),
        );

        $banners = [];
        $monthly = [];
        $totalPulls = 0;
        foreach ($groupedRows as ['group' => $bannerGroup, 'rows' => $rows]) {
            $computed = $statsCalculator->compute($rows, $bannerGroup, $rarityConfig, $now, $rateUp);

            $summary = self::enrichSummaryIcons($iconUrlBuilder, $gameService, $computed['summary']);
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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo,
        HoyoversePullStatsCalculator $statsCalculator,
        PullItemIconUrlBuilder $iconUrlBuilder,
        HoyoverseServerTimezoneResolver $timezoneResolver,
        HoyoverseRateUpLookupFactory $rateUpLookups,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);

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

        // Rarity 0 selects the unknown pulls (filler rows)
        $raritiesParam = $req->query->getString('rarities');
        $rarities = self::parseRarities($raritiesParam !== '' ? $raritiesParam : $req->query->getString('rarity'));

        $search = $req->query->getString('search');
        $page = max(1, $req->query->getInt('page', 1));
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $computed = $statsCalculator->compute(
            $pullRepo->findBannerRows($gameProfile, $bannerGroup['gacha_types']),
            $bannerGroup,
            $rarityConfig,
            $timezoneResolver->getServerWallClockNow($gameProfile),
            $rateUpLookups->forProfile($gameProfile),
        );
        $result = $statsCalculator->filterAndPaginate($computed['items'], $rarities, $search ?: null, $page, $limit);

        $banner = self::enrichSummaryIcons($iconUrlBuilder, $gameService, $computed['summary']);
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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullCardBuilder $cardBuilder,
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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        ManualPullSuggestionProvider $suggestionProvider,
        PullItemIconUrlBuilder $iconUrlBuilder,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);
        $this->assertBannerOfGame($gameService, $req->query->getString('banner'));

        $suggestions = $suggestionProvider->getSuggestions($gameService);

        return $this->json([
            'items' => $iconUrlBuilder->enrichPullRows($gameService, $suggestions['items']),
            // Names typed by hand are kept as they are (without an icon when they match no catalog item)
            'allow_free_text' => true,
            'item_types' => $suggestions['item_types'],
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/manual', name: 'pulls_manual_create', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsManualCreate(
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        #[MapRequestPayload] CreateManualPullRequest $request,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $gameService = $this->getManualPullsGame($gameProfile);
        $this->assertBannerOfGame($gameService, $request->banner);

        $pull = $manualPullService->createManualS($gameProfile, $gameService, $request);

        return $this->json(['success' => true, 'pull_id' => $pull->getId()], Response::HTTP_CREATED);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/{pull}/declared-pity', name: 'pulls_declared_pity', requirements: ['gameProfile' => '\d+', 'pull' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilePullsDeclarePity(
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        #[MapEntity(message: "Pull not found.")] HoyoversePull $pull,
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
        #[MapEntity(message: "Hoyoverse Account not found.")] HoyoverseAccount $account,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        #[MapEntity(message: "Pull not found.")] HoyoversePull $pull,
        ManualPullService $manualPullService,
    ): Response {
        $this->assertProfileOfAccount($account, $gameProfile);
        $this->assertPullOfProfile($gameProfile, $pull);
        $gameService = $this->getManualPullsGame($gameProfile);

        $manualPullService->deleteManual($gameProfile, $gameService, $pull);

        return $this->json(['success' => true]);
    }

    /**
     * Icons of the S items of a banner summary (recent S pulls and the oldest S).
     *
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private static function enrichSummaryIcons(PullItemIconUrlBuilder $iconUrlBuilder, GameInterface $gameService, array $summary): array
    {
        if (!empty($summary['recent_s_pulls'])) {
            $summary['recent_s_pulls'] = $iconUrlBuilder->enrichPullRows($gameService, $summary['recent_s_pulls']);
        }
        if (isset($summary['oldest_s'])) {
            $summary['oldest_s'] = $iconUrlBuilder->enrichPullRows($gameService, [$summary['oldest_s']])[0];
        }
        // Featured items of each phase in the per-phase breakdown
        foreach ($summary['pools'] ?? [] as $index => $pool) {
            if (!empty($pool['featured'])) {
                $summary['pools'][$index]['featured'] = $iconUrlBuilder->enrichPullRows($gameService, $pool['featured']);
            }
        }

        return $summary;
    }

    /**
     * Rarities of a comma-separated filter. Rarity 0 is kept (unknown pulls); empty, negative and
     * non-numeric values are skipped.
     *
     * @return list<int>
     */
    private static function parseRarities(string $param): array
    {
        $rarities = [];
        foreach (explode(',', $param) as $value) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value)) {
                $rarities[] = (int) $value;
            }
        }

        return $rarities;
    }

    /**
     * @return GameInterface&HasPullTrackerInterface
     */
    private function getManualPullsGame(HoyoverseGameProfile $gameProfile): GameInterface
    {
        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
        if (!$gameService instanceof HasPullTrackerInterface || !$gameService instanceof HasManualPullsInterface) {
            throw new BadRequestHttpException("This game profile does not support manual pulls.");
        }

        return $gameService;
    }

    private function assertBannerOfGame(HasPullTrackerInterface $gameService, string $bannerKey): void
    {
        if ($bannerKey === '' || !isset($gameService->getGachaConfig()->getBannerGroups()[$bannerKey])) {
            throw new BadRequestHttpException(sprintf('Unknown banner "%s".', $bannerKey));
        }
    }

    private function assertPullOfProfile(HoyoverseGameProfile $gameProfile, HoyoversePull $pull): void
    {
        if ($pull->getGameProfile()?->getId() !== $gameProfile->getId()) {
            throw new NotFoundHttpException("Pull not found.");
        }
    }

    private function assertProfileOfAccount(HoyoverseAccount $account, HoyoverseGameProfile $gameProfile): void
    {
        if ($gameProfile->getAccount() !== $account) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }
    }
}
