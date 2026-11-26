<?php

namespace App\Tests\PerfectworldBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\PerfectworldBundle\Controller\ApiGameProfilesController;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullStatsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Manual pull endpoints (suggestions, create, declared pity, delete) and the rarity filter of the list.
 */
#[AllowMockObjectsWithoutExpectations]
class ApiGameProfilesManualPullsControllerTest extends TestCase
{
    private PerfectworldAccount $account;
    private PerfectworldGameProfile $profile;
    private NteCatalog $catalog;

    protected function setUp(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $this->account = (new PerfectworldAccount())->setUser($user);
        $this->profile = self::profile(5);
        $this->account->addGameProfile($this->profile);

        $this->catalog = new NteCatalog(new ArrayAdapter());
        $this->catalog->save([
            'sha' => '0123456789abcdef0123456789abcdef01234567',
            'items' => [
                '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'a.png'],
                '1030' => ['id' => '1030', 'name' => 'Adler', 'rank' => 'A', 'type' => 'character', 'icon_path' => null],
                'fork_crime' => ['id' => 'Fork_Crime', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'c.png'],
            ],
        ]);
    }

    private static function profile(int $id): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setGameUid('10000000000' . $id);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    private static function pull(int $id, PerfectworldGameProfile $profile, PullOrigin $origin = PullOrigin::Manual): PerfectworldPull
    {
        $pull = (new PerfectworldPull())
            ->setGameProfile($profile)
            ->setBannerKey(NevernessToEvernessService::BANNER_LIMITED)
            ->setPulledAt(WallClockDateTimeImmutableType::fromString('2026-06-01 10:00:00'))
            ->setGroupPosition(-1)
            ->setOrigin($origin);
        (new \ReflectionProperty(PerfectworldPull::class, 'id'))->setValue($pull, $id);

        return $pull;
    }

    private function createController(): ApiGameProfilesController
    {
        $game = new NevernessToEvernessService(new NullLogger());
        $controller = new ApiGameProfilesController(
            $this->createStub(EntityManagerInterface::class),
            new ServiceLocator([(string) NevernessToEvernessService::GAME_ID => static fn () => $game]),
            sys_get_temp_dir(),
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR));
        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * Real service on stubbed persistence: enough for the checks made before any read or write.
     */
    private function createManualPullService(): ManualPullService
    {
        $planner = new PullGapPlanner();

        return new ManualPullService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PerfectworldPullRepository::class),
            new PerfectworldPullStatsCalculator($planner),
            $planner,
            new ManualPullGuard($planner),
            $this->catalog,
            new LockFactory(new InMemoryStore()),
            new MockClock('2026-06-10 12:00:00'),
        );
    }

    private function createIconUrlBuilder(): PullItemIconUrlBuilder
    {
        $builder = $this->createStub(PullItemIconUrlBuilder::class);
        $builder->method('enrichPullRows')->willReturnCallback(static function (object $game, array $rows): array {
            foreach ($rows as &$row) {
                $row['icon_url'] = ($row['origin'] ?? null) === 'filler' ? null : 'https://icons.test/' . $row['item_id'] . '.png';
            }

            return $rows;
        });

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSuggestionsListTheSItemsOfTheBanner(): void
    {
        $response = $this->createController()->apiGameProfilePullsManualSuggestions(
            $this->account,
            $this->profile,
            $this->createManualPullService(),
            $this->createIconUrlBuilder(),
            NevernessToEvernessService::BANNER_ARC,
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'items' => [[
                'item_id' => 'Fork_Crime',
                'name' => 'Crime and Punishment',
                'item_type' => 'arc',
                'item_type_label' => 'Arc',
                'rarity' => 5,
                'icon_url' => 'https://icons.test/Fork_Crime.png',
            ]],
            'allow_free_text' => false,
            'item_types' => [],
        ], self::decode($response));
    }

    public function testSuggestionsOfAnUnknownBannerAreABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->createController()->apiGameProfilePullsManualSuggestions($this->account, $this->profile, $this->createManualPullService(), $this->createIconUrlBuilder(), 'Lottery_Unknown');
    }

    public function testCreateAnswersTheNewPullId(): void
    {
        $payload = new CreateManualPullRequest(NevernessToEvernessService::BANNER_LIMITED, 'Blackbird', '2026-06-01 09:00:00', 10, itemId: '1042');
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('createManualS')
            ->with($this->profile, $this->isInstanceOf(NevernessToEvernessService::class), $payload)
            ->willReturn(self::pull(42, $this->profile));

        $response = $this->createController()->apiGameProfilePullsManualCreate($this->account, $this->profile, $payload, $service);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(['success' => true, 'pull_id' => 42], self::decode($response));
    }

    public function testCreateOnAnUnknownBannerIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->createController()->apiGameProfilePullsManualCreate(
            $this->account,
            $this->profile,
            new CreateManualPullRequest('Lottery_Unknown', 'Blackbird', '2026-06-01 09:00:00', 10, itemId: '1042'),
            $this->createManualPullService(),
        );
    }

    public function testAPullOfAnotherProfileIsNotFound(): void
    {
        $foreignPull = self::pull(7, self::profile(6));
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->never())->method('declarePity');
        $service->expects($this->never())->method('deleteManual');
        $controller = $this->createController();

        $notFound = 0;
        foreach ([
            fn () => $controller->apiGameProfilePullsDeclaredPity($this->account, $this->profile, $foreignPull, new DeclarePityRequest(20), $service),
            fn () => $controller->apiGameProfilePullsManualDelete($this->account, $this->profile, $foreignPull, $service),
        ] as $action) {
            try {
                $action();
            } catch (NotFoundHttpException) {
                $notFound++;
            }
        }

        $this->assertSame(2, $notFound);
    }

    public function testDeclaredPityAndDeleteDelegateToTheService(): void
    {
        $pull = self::pull(8, $this->profile);
        $service = $this->createMock(ManualPullService::class);
        $service->expects($this->once())->method('declarePity')->with($this->profile, $this->isInstanceOf(NevernessToEvernessService::class), $pull, null);
        $service->expects($this->once())->method('deleteManual')->with($this->profile, $this->isInstanceOf(NevernessToEvernessService::class), $pull);
        $controller = $this->createController();

        $declared = $controller->apiGameProfilePullsDeclaredPity($this->account, $this->profile, $pull, new DeclarePityRequest(null), $service);
        $deleted = $controller->apiGameProfilePullsManualDelete($this->account, $this->profile, $pull, $service);

        $this->assertSame(['success' => true], self::decode($declared));
        $this->assertSame(['success' => true], self::decode($deleted));
    }

    public function testAProfileOfAnotherAccountIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->createController()->apiGameProfilePullsManualDelete($this->account, self::profile(6), self::pull(9, self::profile(6)), $this->createStub(ManualPullService::class));
    }

    public function testTheListAcceptsRarityZeroForUnknownPulls(): void
    {
        $row = static fn (int $id, string $at, string $rewardType, int $rarity, PullOrigin $origin, int $position = 0): array => [
            'id' => $id,
            'bannerKey' => NevernessToEvernessService::BANNER_LIMITED,
            'poolId' => null,
            'pulledAt' => WallClockDateTimeImmutableType::fromString($at),
            'groupPosition' => $position,
            'kind' => PerfectworldPull::KIND_ROLL,
            'rollResult' => null,
            'rewardType' => $rewardType,
            'rewardId' => $origin === PullOrigin::Filler ? ManualPullService::FILLER_REWARD_ID : '1042',
            'name' => $origin === PullOrigin::Filler ? 'Unknown' : 'Blackbird',
            'rarity' => $rarity,
            'quantity' => 1,
            'origin' => $origin,
            'declaredPity' => $origin === PullOrigin::Recorded && $rarity === 5 ? 3 : null,
        ];
        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBannerRows')->willReturn([
            $row(1, '2026-06-01 09:59:59', 'unknown', 0, PullOrigin::Filler, -1),
            $row(2, '2026-06-01 09:59:59', 'unknown', 0, PullOrigin::Filler, -2),
            $row(3, '2026-06-01 10:00:00', 'character', 5, PullOrigin::Recorded),
        ]);

        $response = $this->createController()->apiGameProfilePullsList(
            new Request(['banner' => NevernessToEvernessService::BANNER_LIMITED, 'rarities' => '0, ,-1,abc']),
            $this->account,
            $this->profile,
            $repository,
            new PerfectworldPullStatsCalculator(new PullGapPlanner()),
            $this->createIconUrlBuilder(),
            $this->catalog,
        );
        $data = self::decode($response);

        $this->assertSame(2, $data['total']);
        $this->assertSame([2, 1], array_column($data['items'], 'id'), 'Newest first');
        $this->assertSame(['filler', 'filler'], array_column($data['items'], 'origin'));
        $this->assertSame([null, null], array_column($data['items'], 'icon_url'));
        $this->assertSame(2, $data['banner']['unknown_count']);
        $this->assertSame(3, $data['banner']['oldest_s']['pity']);
        $this->assertSame('https://icons.test/1042.png', $data['banner']['oldest_s']['icon_url'], 'oldest_s gets its icon too');
        $this->assertSame('https://icons.test/1042.png', $data['banner']['recent_s_pulls'][0]['icon_url']);
    }

    public function testTheArcListShowsTheRateUpAndTheRotations(): void
    {
        $this->catalog->save([
            'sha' => '0123456789abcdef0123456789abcdef01234567',
            'items' => ['fork_elyms' => ['id' => 'fork_elyms', 'name' => 'Echoes on Hold', 'rank' => 'S', 'type' => 'arc', 'icon_path' => null]],
            'pools' => ['forklottery_yeermu' => 'Echo Special'],
            'banners' => [
                'forklottery_yeermu' => ['id' => 'ForkLottery_YeErMu', 'category' => 'arc', 'name' => 'Echo Special', 'up' => ['fork_elyms'], 'others' => ['fork_BlackBook'], 'up_guarantee' => 80],
            ],
        ]);
        $row = static fn (int $id, string $at, string $rewardId, int $rarity, ?string $poolId): array => [
            'id' => $id,
            'bannerKey' => NevernessToEvernessService::BANNER_ARC,
            'poolId' => $poolId,
            'pulledAt' => WallClockDateTimeImmutableType::fromString($at),
            'groupPosition' => 0,
            'kind' => PerfectworldPull::KIND_DRAW,
            'rollResult' => null,
            'rewardType' => 'arc',
            'rewardId' => $rewardId,
            'name' => $rewardId,
            'rarity' => $rarity,
            'quantity' => 1,
            'origin' => PullOrigin::Recorded,
            'declaredPity' => null,
        ];
        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBannerRows')->willReturn([
            $row(1, '2026-04-30 14:00:00', 'fork_BlackBook', 5, null),
            $row(2, '2026-11-11 16:00:00', 'fork_banshu01', 3, 'ForkLottery_YeErMu'),
            $row(3, '2026-11-11 16:01:00', 'fork_elyms', 5, 'ForkLottery_YeErMu'),
        ]);

        $data = self::decode($this->createController()->apiGameProfilePullsList(
            new Request(['banner' => NevernessToEvernessService::BANNER_ARC, 'rarities' => '5,4,3']),
            $this->account,
            $this->profile,
            $repository,
            new PerfectworldPullStatsCalculator(new PullGapPlanner(), $this->catalog),
            $this->createIconUrlBuilder(),
            $this->catalog,
        ));

        $this->assertSame([3 => true, 2 => null, 1 => false], array_column($data['items'], 'is_up', 'id'));
        $this->assertSame([true, false], array_column($data['banner']['recent_s_pulls'], 'is_up'));
        $this->assertSame(
            [['Arc Research Program', 1, 1], ['Echo Special', 2, 1]],
            array_map(static fn (array $pool): array => [$pool['pool_name'], $pool['total_pulls'], $pool['s_count']], $data['banner']['pools']),
        );
    }
}
