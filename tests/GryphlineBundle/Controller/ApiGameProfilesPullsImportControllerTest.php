<?php

namespace App\Tests\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GryphlineBundle\Controller\ApiGameProfilesController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Pull\PullBackupImporterInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullBackupImporterManager;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiGameProfilesPullsImportControllerTest extends TestCase
{
    private string $tempProjectDir;

    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . '/valdi_gryph_ctrl_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempProjectDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempProjectDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createUser(int $id = 1): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * @return array{GryphlineAccount, GryphlineGameProfile}
     */
    private function createAccountAndProfile(User $user, int $profileId = 10): array
    {
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameBiz(ArknightsEndfieldService::GAME_BIZ)
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $profileId);

        return [$account, $profile];
    }

    private function createArknightsEndfieldService(): ArknightsEndfieldService
    {
        return new ArknightsEndfieldService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $this->createStub(DenormalizerInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger(),
        );
    }

    private function createController(): ApiGameProfilesController
    {
        $endfield = $this->createArknightsEndfieldService();
        $locator = new ServiceLocator([
            ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield,
        ]);

        $controller = new ApiGameProfilesController(
            $this->createStub(EntityManagerInterface::class),
            $locator,
            $this->tempProjectDir,
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR)
        );

        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @param list<string> $extensions
     */
    private function createFakeImporter(
        string $key,
        array $extensions,
        PullBackupValidationResult $validationResult,
    ): PullBackupImporterInterface {
        return new class($key, $extensions, $validationResult) implements PullBackupImporterInterface {
            private static string $staticKey = '';

            /**
             * @param list<string> $extensions
             */
            public function __construct(
                private readonly string $key,
                private readonly array $extensions,
                private readonly PullBackupValidationResult $validationResult,
            ) {
                self::$staticKey = $key;
            }

            public static function getKey(): string
            {
                return self::$staticKey;
            }

            public function getLabel(): string
            {
                return 'Label ' . $this->key;
            }

            public function getDescription(): string
            {
                return 'Desc';
            }

            public function getAcceptedExtensions(): array
            {
                return $this->extensions;
            }

            public static function getSupportedGameServices(): array
            {
                return [ArknightsEndfieldService::class];
            }

            public function validate(string $filePath): PullBackupValidationResult
            {
                return $this->validationResult;
            }

            public function parse(GryphlineGameProfile $profile, string $filePath): array
            {
                return [];
            }
        };
    }

    private function createUploadedFile(string $content, string $originalName): UploadedFile
    {
        $source = $this->tempProjectDir . '/upload_' . bin2hex(random_bytes(4)) . '_' . $originalName;
        file_put_contents($source, $content);

        return new UploadedFile($source, $originalName, 'application/json', null, true);
    }

    public function testPullsSyncStartsOperationAndDispatchesBannerMessages(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        // One message per sync stream (Chartered, RE-Factor, Special, Basic, weapons, Beginner)
        $bus->expects($this->exactly(6))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $response = $controller->apiGameProfilePullsSync($user, $account, $profile, $bus, $tracker);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('auto_sync', $payload['operation']['kind']);
        $this->assertSame(6, $payload['operation']['total']);

        foreach ($dispatched as $message) {
            $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            $this->assertSame($payload['operation']['id'], $message->getOperationId());
        }
    }

    public function testPullsOverviewSetsMercureCookieAndReturnsProgressMetadata(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);

        $subscribedTopics = [];
        $tokenFactory = $this->createStub(TokenFactoryInterface::class);
        $tokenFactory->method('create')->willReturnCallback(
            static function (?array $subscribe = [], ?array $publish = [], array $additionalClaims = []) use (&$subscribedTopics): string {
                $subscribedTopics = $subscribe ?? [];

                return 'fake-jwt';
            }
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://gryphline.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn($tokenFactory);

        $authorization = new Authorization(new HubRegistry($hub));
        $tracker = new PullOperationTracker(new ArrayAdapter(), $hub, 'games.test');
        $activeOp = $tracker->startSyncOperation(10, 1, \App\GamesBundle\Model\Pull\PullOperationKind::AUTO_SYNC, ['char' => 'Character']);

        $request = Request::create('https://gryphline.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET');
        $response = $controller->apiGameProfilePullsOverview(
            $request,
            $account,
            $profile,
            $pullRepo,
            new GryphlinePullStatsCalculator(new PullGapPlanner()),
            $this->createStub(PullItemIconUrlBuilder::class),
            new PullBackupImporterManager([]),
            $tracker,
            $authorization,
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $payload['total_pulls']);
        $this->assertCount(7, $payload['banners'], 'Configured banners are listed even without pulls');
        $this->assertSame([], $payload['monthly_timeline']);
        $this->assertSame([], $payload['available_backup_importers']);
        $this->assertSame('https://games.test/gryphline/profiles/10/pulls/progress', $payload['mercure_topic']);
        $this->assertSame($activeOp['id'], $payload['active_operation']['id']);
        $this->assertNotEmpty($request->attributes->get('_mercure_authorization_cookies'));
    }

    /**
     * @return array<string, mixed>
     */
    private function pullRow(int $id, string $poolType, string $pulledAt, int $rarity, string $name, ?string $poolName = 'Winter Hunt'): array
    {
        return [
            'id' => $id,
            'seqId' => (string) (100 + $id),
            'poolType' => $poolType,
            'poolCategory' => str_starts_with($poolType, 'E_Weapon') ? 'weapon' : 'char',
            'poolId' => 'pool_1',
            'poolName' => $poolName,
            'itemId' => 'item_' . $id,
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($pulledAt),
        ];
    }

    public function testPullsListLeavesNoIconOnABonusEntryWithoutItem(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);

        $dossier = ['itemId' => null, 'rarity' => null, 'kind' => 'gift_intel_book']
            + $this->pullRow(2, 'E_CharacterGachaPoolType_Special', '2026-06-01 10:01:00', 4, 'Headhunting Dossier');
        $tribute = ['itemId' => 'wpn_tribute', 'rarity' => 6, 'kind' => 'gift_weapon']
            + $this->pullRow(3, 'E_CharacterGachaPoolType_Special', '2026-06-01 10:02:00', 4, 'Tribute weapon');
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn([
            $this->pullRow(1, 'E_CharacterGachaPoolType_Special', '2026-06-01 10:00:00', 4, 'Item'),
            $dossier,
            $tribute,
        ]);

        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', ['banner' => 'E_CharacterGachaPoolType_Special']);
        // The real builder, which owns the rule: only the CDN lookup and the LiipImagine URLs are faked
        $resolver = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [ArknightsEndfieldService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn.test/' . $query->itemId . '.png';
            }
        };
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willReturnCallback(static fn (string $path, string $filter): string => $filter . ':' . $path);
        $iconUrlBuilder = new PullItemIconUrlBuilder($cacheManager, new PullItemIconPathCodec(), new PullItemIconResolverManager([$resolver]));

        $response = $this->createController()->apiGameProfilePullsList($request, $account, $profile, $pullRepo, new GryphlinePullStatsCalculator(new PullGapPlanner()), $iconUrlBuilder);

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = array_column($payload['items'], null, 'name');
        // The dossier has no item: its images would be looked up by name and never found
        $this->assertNull($items['Headhunting Dossier']['icon_url']);
        $this->assertNull($items['Headhunting Dossier']['preview_url']);
        $this->assertStringEndsWith('/wpn_tribute.webp', $items['Tribute weapon']['icon_url'], 'A bonus entry with an item keeps its icon');
        $this->assertStringStartsWith(PullItemIconUrlBuilder::PREVIEW_FILTER_NAME . ':', $items['Tribute weapon']['preview_url']);
        $this->assertStringEndsWith('/item_1.webp', $items['Item']['icon_url']);
    }

    private function createIconUrlBuilder(): PullItemIconUrlBuilder
    {
        $iconUrlBuilder = $this->createStub(PullItemIconUrlBuilder::class);
        $iconUrlBuilder->method('enrichPullRows')->willReturnCallback(
            static fn (object $game, array $rows): array => array_map(static fn (array $row): array => $row + ['icon_url' => 'icon/' . $row['item_id']], $rows)
        );

        return $iconUrlBuilder;
    }

    public function testPullsOverviewComputesEachBannerAndMergesTheMonthlyTimeline(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([
            $this->pullRow(1, 'E_CharacterGachaPoolType_Special', '2026-05-10 10:00:00', 4, 'Item'),
            $this->pullRow(2, 'E_CharacterGachaPoolType_Special', '2026-05-10 10:00:00', 6, 'Liino'),
            $this->pullRow(3, 'E_WeaponGachaPoolType_Standard', '2026-06-01 10:00:00', 5, 'Aspirant', 'Deep Cold Issue'),
            $this->pullRow(4, 'E_CharacterGachaPoolType_Collab', '2026-06-02 10:00:00', 6, 'Guest', 'Collab Hunt'),
            $this->pullRow(5, 'E_CharacterGachaPoolType_Special', '2026-06-03 10:00:00', 4, 'Item'),
        ]);

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://gryphline.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));

        $response = $controller->apiGameProfilePullsOverview(
            Request::create('https://gryphline.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET'),
            $account,
            $profile,
            $pullRepo,
            new GryphlinePullStatsCalculator(new PullGapPlanner()),
            $this->createIconUrlBuilder(),
            new PullBackupImporterManager([]),
            new PullOperationTracker(new ArrayAdapter(), $hub, 'games.test'),
            new Authorization(new HubRegistry($hub)),
        );

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5, $payload['total_pulls']);
        $this->assertSame([
            'E_CharacterGachaPoolType_Special',
            'E_CharacterGachaPoolType_Rerun',
            'E_CharacterGachaPoolType_Joint',
            'E_CharacterGachaPoolType_Standard',
            'E_WeaponGachaPoolType_Standard',
            'E_WeaponGachaPoolType_Rerun',
            'E_CharacterGachaPoolType_Beginner',
            'E_CharacterGachaPoolType_Collab',
        ], array_column($payload['banners'], 'key'), 'Configured banners first, then unconfigured pool types');
        $this->assertSame([3, 0, 0, 0, 1, 0, 0, 1], array_column($payload['banners'], 'total_pulls'));
        $this->assertSame('Collab Hunt', $payload['banners'][7]['name']);
        $this->assertSame([80, 10], [$payload['banners'][7]['s_max_pity'], $payload['banners'][7]['a_max_pity']]);
        $this->assertSame(1, $payload['banners'][0]['s_pity']);
        $this->assertSame('icon/item_2', $payload['banners'][0]['recent_s_pulls'][0]['icon_url']);
        $this->assertSame('icon/item_2', $payload['banners'][0]['oldest_s']['icon_url'], 'oldest_s is enriched like recent_s_pulls');
        $this->assertNull($payload['banners'][1]['oldest_s']);
        $this->assertSame([
            ['month' => '2026-05', 'total' => 2, 's_count' => 1, 'a_count' => 0],
            ['month' => '2026-06', 'total' => 3, 's_count' => 1, 'a_count' => 1],
        ], $payload['monthly_timeline']);
    }

    public function testPullsListComputesTheBannerThenFiltersAndPaginates(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $rows = [];
        for ($id = 1; $id <= 12; $id++) {
            $rarity = match ($id) {
                3, 11 => 6,
                5, 8, 12 => 5,
                default => 4,
            };
            $rows[] = $this->pullRow($id, 'E_WeaponGachaPoolType_Standard', sprintf('2026-06-01 10:%02d:00', $id), $rarity, 'Weapon ' . $id);
        }

        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->once())
            ->method('findBannerRows')
            ->with($profile, ['E_WeaponGachaPoolType_Standard'])
            ->willReturn($rows);

        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', [
            'banner' => 'E_WeaponGachaPoolType_Standard',
            'rarities' => '5,4',
            'page' => '2',
            'limit' => '3',
        ]);
        $response = $controller->apiGameProfilePullsList($request, $account, $profile, $pullRepo, new GryphlinePullStatsCalculator(new PullGapPlanner()), $this->createIconUrlBuilder());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10, $payload['total'], 'Every A and B headhunt');
        $this->assertSame(2, $payload['page']);
        $this->assertSame(5, $payload['limit'], 'The limit is at least 5');
        $this->assertSame([6, 5, 4, 2, 1], array_column($payload['items'], 'id'), 'Newest first, second page');
        $this->assertSame([1, 2, 1, 1, 1], array_column($payload['items'], 'pity'), 'Pity is computed on the whole banner, before filtering');
        $this->assertSame('icon/item_6', $payload['items'][0]['icon_url']);

        $banner = $payload['banner'];
        $this->assertSame('Arsenal Issue', $banner['name']);
        $this->assertSame(12, $banner['total_pulls']);
        $this->assertSame(6000, $banner['currency_spent']);
        $this->assertSame([2, 3, 7], [$banner['s_count'], $banner['a_count'], $banner['b_count']]);
        $this->assertSame([1, 0], [$banner['s_pity'], $banner['a_pity']], 'The last S reset the A pity, then an A');
        $this->assertSame(['icon/item_11', 'icon/item_3'], array_column($banner['recent_s_pulls'], 'icon_url'));
        $this->assertSame(3, $banner['oldest_s']['id']);
        $this->assertSame('icon/item_3', $banner['oldest_s']['icon_url']);
    }

    public function testPullsOverviewAndListGiveTheFeaturedItemsOfEachBannerTheirIcons(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $catalog = new AkeCatalog(new ArrayAdapter());
        $catalog->save([
            'version' => 'v1',
            'built_at' => 'now',
            'items' => [
                'chr_0013_aglina' => ['id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'rarity' => 6, 'type' => 'char'],
            ],
            'pools' => [
                'special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul', 'runs' => []],
            ],
        ]);
        $calculator = new GryphlinePullStatsCalculator(new PullGapPlanner(), $catalog);
        // Chartered Headhunting lists its banners: one in the catalog, one unknown (pool_1)
        $rows = [
            ['poolId' => 'special_1_0_3'] + $this->pullRow(1, 'E_CharacterGachaPoolType_Special', '2026-06-01 10:00:00', 4, 'Item'),
            $this->pullRow(2, 'E_CharacterGachaPoolType_Special', '2026-06-20 10:00:00', 4, 'Item'),
        ];
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn($rows);
        $pullRepo->method('findBannerRows')->willReturn($rows);

        $hub = $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://gryphline.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn($this->createStub(TokenFactoryInterface::class));

        $overview = $controller->apiGameProfilePullsOverview(
            Request::create('https://gryphline.test/api/accounts/1/gameProfiles/10/pulls/overview', 'GET'),
            $account,
            $profile,
            $pullRepo,
            $calculator,
            $this->createIconUrlBuilder(),
            new PullBackupImporterManager([]),
            new PullOperationTracker(new ArrayAdapter(), $hub, 'games.test'),
            new Authorization(new HubRegistry($hub)),
        );
        $list = $controller->apiGameProfilePullsList(
            Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', ['banner' => 'E_CharacterGachaPoolType_Special']),
            $account,
            $profile,
            $pullRepo,
            $calculator,
            $this->createIconUrlBuilder(),
        );

        $overviewBanner = json_decode((string) $overview->getContent(), true, 512, JSON_THROW_ON_ERROR)['banners'][0];
        $listBanner = json_decode((string) $list->getContent(), true, 512, JSON_THROW_ON_ERROR)['banner'];
        $this->assertSame('E_CharacterGachaPoolType_Special', $overviewBanner['key']);
        foreach (['overview' => $overviewBanner, 'list' => $listBanner] as $endpoint => $banner) {
            $this->assertSame(['special_1_0_3', 'pool_1'], array_column($banner['pools'], 'pool_id'), $endpoint);
            $this->assertSame(
                [['item_id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'item_type' => 'char', 'rarity' => 6, 'icon_url' => 'icon/chr_0013_aglina']],
                $banner['pools'][0]['featured'],
                $endpoint . ': the featured items go through the icon pipeline like the pulls',
            );
            $this->assertSame([], $banner['pools'][1]['featured'], $endpoint . ': nothing featured by an unknown banner');
        }
    }

    public function testPullsListAcceptsRarityZeroForUnknownPulls(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $rows = [];
        foreach ([[4, 'recorded'], [0, 'filler'], [0, 'filler'], [6, 'recorded'], [5, 'recorded']] as $i => [$rarity, $origin]) {
            $row = $this->pullRow($i + 1, 'E_CharacterGachaPoolType_Special', sprintf('2026-06-01 10:%02d:00', $i), $rarity, $origin === 'filler' ? 'Unknown' : 'Item ' . $i);
            $row['origin'] = PullOrigin::from($origin);
            $rows[] = $row;
        }
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn($rows);

        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls', 'GET', [
            'banner' => 'E_CharacterGachaPoolType_Special',
            'rarities' => '0, abc,-1,,4',
        ]);
        $response = $controller->apiGameProfilePullsList($request, $account, $profile, $pullRepo, new GryphlinePullStatsCalculator(new PullGapPlanner()), $this->createIconUrlBuilder());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $payload['total'], '0 (unknown) and 4 kept; "abc", "-1" and empty values skipped');
        $this->assertSame([3, 2, 1], array_column($payload['items'], 'id'));
        $this->assertSame(['filler', 'filler', 'recorded'], array_column($payload['items'], 'origin'));
        $this->assertSame([null, null, 1], array_column($payload['items'], 'pity'));
        $this->assertSame(2, $payload['banner']['unknown_count']);
    }

    private function createManualPullService(GryphlinePullRepository $pullRepo, ?AkeCatalog $catalog = null): ManualPullService
    {
        $planner = new PullGapPlanner();

        return new ManualPullService(
            $this->createStub(EntityManagerInterface::class),
            $pullRepo,
            new GryphlinePullStatsCalculator($planner),
            $planner,
            new ManualPullGuard($planner),
            new LockFactory(new InMemoryStore()),
            $catalog ?? new AkeCatalog(new ArrayAdapter()),
            new MockClock('2026-11-22 12:00:00', 'UTC'),
            new NullLogger(),
        );
    }

    public function testManualSuggestionsListTheBannerSItemsWithIcons(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $catalog = new AkeCatalog(new ArrayAdapter());
        $catalog->save(['version' => 'v1', 'built_at' => 'now', 'items' => [
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 6, 'type' => 'char'],
        ]]);
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findDistinctRecordedItems')->willReturn([]);

        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/manual/suggestions', 'GET', ['banner' => 'E_WeaponGachaPoolType_Standard']);
        $response = $controller->apiGameProfilePullsManualSuggestions($request, $account, $profile, $this->createManualPullService($pullRepo, $catalog), $this->createIconUrlBuilder());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'items' => [[
                'item_id' => 'wpn_funnel_0011',
                'name' => 'Delivery Guaranteed',
                'item_type' => 'weapon',
                'item_type_label' => 'Weapon',
                'rarity' => 6,
                'icon_url' => 'icon/wpn_funnel_0011',
            ]],
            'allow_free_text' => true,
            'item_types' => [],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testManualEndpointsRejectAnUnknownBanner(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();
        $service = $this->createManualPullService($this->createStub(GryphlinePullRepository::class));

        try {
            $controller->apiGameProfilePullsManualSuggestions(
                Request::create('/', 'GET', ['banner' => 'E_Unknown']),
                $account,
                $profile,
                $service,
                $this->createIconUrlBuilder(),
            );
            $this->fail('Suggestions of an unknown banner');
        } catch (BadRequestHttpException) {
        }

        $this->expectException(BadRequestHttpException::class);
        $controller->apiGameProfilePullsManualCreate(
            $account,
            $profile,
            new CreateManualPullRequest(banner: 'E_Unknown', name: 'Chen', pulledAt: '2026-06-01 10:00:00', pity: 10),
            $service,
        );
    }

    public function testManualCreateReturnsTheNewPullId(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $request = new CreateManualPullRequest(banner: 'E_CharacterGachaPoolType_Special', name: 'Chen', pulledAt: '2026-06-01 10:00:00', pity: 10);
        $pull = new GryphlinePull();
        (new \ReflectionProperty(GryphlinePull::class, 'id'))->setValue($pull, 321);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('createManualS')->with($profile, $this->isInstanceOf(ArknightsEndfieldService::class), $request)->willReturn($pull);

        $response = $controller->apiGameProfilePullsManualCreate($account, $profile, $request, $service);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(['success' => true, 'pull_id' => 321], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDeclaredPityAndDeleteReturn404ForAPullOfAnotherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        [, $otherProfile] = $this->createAccountAndProfile($user, 11);
        $controller = $this->createController();

        $pull = (new GryphlinePull())->setGameProfile($otherProfile)->setOrigin(PullOrigin::Manual);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->never())->method($this->anything());

        try {
            $controller->apiGameProfilePullsDeclaredPity($account, $profile, $pull, new DeclarePityRequest(5), $service);
            $this->fail('Declared pity on a pull of another profile');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsManualDelete($account, $profile, $pull, $service);
    }

    public function testDeclaredPityAndDeleteDelegateToTheService(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $pull = (new GryphlinePull())->setGameProfile($profile)->setOrigin(PullOrigin::Manual);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('declarePity')->with($profile, $this->isInstanceOf(ArknightsEndfieldService::class), $pull, null);
        $service->expects($this->once())->method('deleteManual')->with($profile, $this->isInstanceOf(ArknightsEndfieldService::class), $pull);

        $declared = $controller->apiGameProfilePullsDeclaredPity($account, $profile, $pull, new DeclarePityRequest(null), $service);
        $deleted = $controller->apiGameProfilePullsManualDelete($account, $profile, $pull, $service);

        $this->assertSame('{"success":true}', $declared->getContent());
        $this->assertSame('{"success":true}', $deleted->getContent());
    }

    public function testImportBackupAcceptsValidFileAndDispatchesMessage(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::ok(25));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $file = $this->createUploadedFile('{"pulls":[]}', 'endfield.json');
        $request = Request::create(
            '/api/accounts/1/gameProfiles/10/pulls/import-backup',
            'POST',
            ['importer' => 'endfield_export'],
            [],
            ['file' => $file]
        );

        $response = $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('backup_import', $payload['operation']['kind']);
        $this->assertSame(25, $payload['operation']['total']);

        $this->assertCount(1, $dispatched);
        $msg = $dispatched[0];
        $this->assertInstanceOf(ImportPullsBackupMessage::class, $msg);
        $this->assertSame($payload['operation']['id'], $msg->getOperationId());
        $this->assertFileExists($msg->getFilePath());
    }

    public function testImportBackupRejectsInvalidFormatAndCleansUpTempFile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::fail('Invalid export schema.'));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $file = $this->createUploadedFile('{}', 'bad.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'endfield_export'], [], ['file' => $file]);

        try {
            $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $bus);
            $this->fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Invalid export schema.', $e->getMessage());
        }

        $tempDir = $this->tempProjectDir . '/var/tmp/gryphline_pull_imports';
        $remaining = is_dir($tempDir) ? array_diff(scandir($tempDir) ?: [], ['.', '..']) : [];
        $this->assertSame([], $remaining);
    }

    public function testImportBackupReturnsConflictWhenAnotherBackupIsRunning(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $importer = $this->createFakeImporter('endfield_export', ['.json'], PullBackupValidationResult::ok(10));
        $importerManager = new PullBackupImporterManager([$importer]);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 10);

        $file = $this->createUploadedFile('{}', 'endfield.json');
        $request = Request::create('/api/accounts/1/gameProfiles/10/pulls/import-backup', 'POST', ['importer' => 'endfield_export'], [], ['file' => $file]);

        $this->expectException(ConflictHttpException::class);
        $controller->apiGameProfilePullsImportBackup($request, $user, $account, $profile, $importerManager, $tracker, $this->createStub(MessageBusInterface::class));
    }

    public function testOperationStatusReturnsSnapshotOrNotFoundForOtherProfile(): void
    {
        $user = $this->createUser(1);
        [$account, $profile] = $this->createAccountAndProfile($user, 10);
        $controller = $this->createController();

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $op = $tracker->startBackupImport(10, 1, 'endfield_export', 'Endfield Export', 10);
        $otherOp = $tracker->startBackupImport(99, 1, 'endfield_export', 'Endfield Export', 10);

        $okResponse = $controller->apiGameProfilePullsOperationStatus($account, $profile, $op['id'], $tracker);
        $this->assertSame(Response::HTTP_OK, $okResponse->getStatusCode());
        $payload = json_decode((string) $okResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($op['id'], $payload['operation']['id']);

        $this->expectException(NotFoundHttpException::class);
        $controller->apiGameProfilePullsOperationStatus($account, $profile, $otherOp['id'], $tracker);
    }
}
