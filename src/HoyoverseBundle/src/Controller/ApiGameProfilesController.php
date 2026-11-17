<?php

namespace App\HoyoverseBundle\Controller;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Repository\HoyoverseDiaryEntryRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[ResolveEntityFromRoute(setter: 'setAccount')]
#[IsGranted('ROLE_USER_HOYOVERSE', null, 'Access Denied.')]
#[Route('/api/accounts/{account}', name: 'api_hoyoverse_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private HoyoverseAccount $account;

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameId
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
            /** @var GameInterface $gameService */
            $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
            $currenciesEnum = GameDiaryCurrency::forGameService($gameService);
            foreach ($currenciesEnum as $curr) {
                $currencies[] = [
                    'value' => $curr->value,
                    'label' => $curr->getLabel(),
                    'api_type' => $curr->getApiType(),
                ];
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
        MessageBusInterface $bus
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

        $delaySeconds = 0;
        foreach (array_keys($gameService->getGachaBannerTypes()) as $gachaType) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage($gameProfile->getId(), (string) $gachaType, '0'),
                $stamps
            );
            $delaySeconds += 15;
        }

        return $this->json([
            'success' => true,
            'message' => 'Pulls synchronization queued in background.',
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/import-powershell', name: 'pulls_import_powershell', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsImportPowershell(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        MessageBusInterface $bus
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

        $authkey = (string) $extractedParams['authkey'];
        unset($extractedParams['authkey'], $extractedParams['gacha_type'], $extractedParams['page'], $extractedParams['size'], $extractedParams['end_id']);

        $delaySeconds = 0;
        foreach (array_keys($gameService->getGachaBannerTypes()) as $gachaType) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $gameProfile->getId(),
                    (string) $gachaType,
                    '0',
                    $authkey,
                    $extractedParams
                ),
                $stamps
            );
            $delaySeconds += 15;
        }

        return $this->json([
            'success' => true,
            'message' => 'PowerShell pulls import queued in background.',
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/overview', name: 'pulls_overview', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOverview(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo
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

        $bannerGroups = $gameService->getGachaBannerGroups();
        $rarityConfig = $gameService->getRarityConfig();
        $overview = $pullRepo->buildOverview($gameProfile, $bannerGroups, $rarityConfig);

        return $this->json([
            'profile' => $gameProfile,
            'rarity_config' => $rarityConfig,
            'total_pulls' => $overview['total_pulls'],
            'banners' => $overview['banners'],
            'monthly_timeline' => $overview['monthly_timeline'],
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls', name: 'pulls_list', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsList(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        HoyoversePullRepository $pullRepo
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

        $bannerGroups = $gameService->getGachaBannerGroups();
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

        return $this->json([
            'banner' => $result['banner'],
            'rarity_config' => $rarityConfig,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}
