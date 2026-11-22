<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\RetrievePullsDataException;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
use App\HoyoverseBundle\Service\Pull\PullSyntheticReconcilerManager;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * SyncPullsPageProfileHandler on Zenless Zone Zero: accounts without stoken_v2 read the pages from the HoYoLAB
 * battle chronicle with the same pagination, stop, tracker and realign; every other case keeps getGachaLog.
 */
class SyncPullsChronicleHandlerTest extends TestCase
{
    private const int PROFILE_ID = 20;

    private HoyoverseGameProfile $profile;
    private ZenlessZoneZeroService&MockObject $zzzService;
    private HoyolabCookieUtilsService&MockObject $cookieUtils;
    private PullOperationTracker $tracker;

    /** @var list<object> */
    private array $persisted = [];

    private function setUpProfile(bool $withStoken): void
    {
        $cookie = (new ParsedCookie())->setLtokenV2('v2_lt')->setLtuidV2('111')->setLtmidV2('mid111');
        if ($withStoken) {
            $cookie->setStokenV2('v2_st');
        }

        $this->profile = (new HoyoverseGameProfile())
            ->setAccount((new HoyoverseAccount())->setCookie($cookie->toStorageString()))
            ->setGameId(ZenlessZoneZeroService::GAME_ID)
            ->setGameBiz(ZenlessZoneZeroService::GAME_BIZ)
            ->setGameUid('1500000042')
            ->setRegion('prod_gf_eu')
            ->setNickname('Proxy');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($this->profile, self::PROFILE_ID);

        $this->cookieUtils = $this->createMock(HoyolabCookieUtilsService::class);
        $this->cookieUtils->method('parseCookie')->willReturn($cookie);

        $this->zzzService = $this->getMockBuilder(ZenlessZoneZeroService::class)
            ->setConstructorArgs([new NullLogger()])
            ->onlyMethods(['fetchPullsPage', 'fetchChroniclePullsPage'])
            ->getMock();

        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
    }

    private function createHandler(
        MessageBusInterface $bus,
        ManualPullService $manualPullService,
        ?HoyoversePullRepository $pullRepository = null,
    ): SyncPullsPageProfileHandler {
        $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($this->profile);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $handler = new SyncPullsPageProfileHandler(
            $profileRepo,
            $this->cookieUtils,
            new ServiceLocator([ZenlessZoneZeroService::GAME_ID => fn () => $this->zzzService]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
        );
        $handler->setEntityManager($em);
        $handler->setPullRepository($pullRepository ?? $this->createStub(HoyoversePullRepository::class));
        $handler->setBus($bus);
        $handler->setSyntheticReconcilerManager(new PullSyntheticReconcilerManager([]));
        $handler->setOperationTracker($this->tracker);
        $handler->setLockFactory(new LockFactory(new InMemoryStore()));
        $handler->setManualPullService($manualPullService);
        $handler->setPullSyncSourceResolver(new PullSyncSourceResolver($this->cookieUtils));

        return $handler;
    }

    /**
     * @return list<HoyoversePull>
     */
    private function page(int $count, int $firstId = 1795000000000012553): array
    {
        $pulls = [];
        for ($i = 0; $i < $count; ++$i) {
            $pulls[] = (new HoyoversePull())
                ->setPullId((string) ($firstId - $i * 100))
                ->setGachaType('2')
                ->setGachaName('Exclusive Channel')
                ->setItemType('W-Engines')
                ->setName('[Cinder] Cobalt')
                ->setRarity(2)
                ->setPulledAt(new \DateTimeImmutable('2026-11-07 21:05:10', new \DateTimeZone('UTC')))
                ->setGameProfile($this->profile);
        }

        return $pulls;
    }

    private function startOperation(): string
    {
        return (string) $this->tracker->startSyncOperation(self::PROFILE_ID, 1, PullOperationKind::STOKEN_SYNC, ['2' => 'Exclusive Channel'])['id'];
    }

    public function testCookieOnlyAccountReadsAFullPageFromTheChronicleAndQueuesTheNextOne(): void
    {
        $this->setUpProfile(withStoken: false);
        $page = $this->page(20);
        $operationId = $this->startOperation();

        $this->cookieUtils->expects($this->never())->method('generateAuthKey');
        $this->zzzService->expects($this->never())->method('fetchPullsPage');
        $this->zzzService->expects($this->once())
            ->method('fetchChroniclePullsPage')
            ->with(
                $this->callback(fn (RuntimeAccountData $account): bool => $account->getGameProfile() === $this->profile
                    && $account->getParsedCookie()->getStokenV2() === null),
                '2',
                '0'
            )
            ->willReturn($page);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $message, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            });
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->never())->method('realign');

        $this->createHandler($bus, $manualPullService)(new SyncPullsPageProfileMessage(self::PROFILE_ID, '2', '0', null, [], $operationId));

        $this->assertSame($page, $this->persisted);

        [$next, $stamps] = $dispatched[0];
        $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $next);
        $this->assertSame('2', $next->getGachaType());
        $this->assertSame($page[19]->getPullId(), $next->getEndId());
        $this->assertNull($next->getAuthkey());
        $this->assertSame($operationId, $next->getOperationId());
        $this->assertInstanceOf(DelayStamp::class, $stamps[0]);
        $this->assertGreaterThanOrEqual(1500, $stamps[0]->getDelay());
        $this->assertLessThanOrEqual(2500, $stamps[0]->getDelay());

        $banner = $this->tracker->getOperation($operationId)['banners']['2'];
        $this->assertSame('running', $banner['status']);
        $this->assertSame(1, $banner['pages']);
        $this->assertSame(20, $banner['imported']);
    }

    public function testCookieOnlyAccountStopsOnTheFirstKnownPullAndRealigns(): void
    {
        $this->setUpProfile(withStoken: false);
        $page = $this->page(3, 1795000000000010053);
        $operationId = $this->startOperation();

        $this->cookieUtils->expects($this->never())->method('generateAuthKey');
        $this->zzzService->expects($this->once())
            ->method('fetchChroniclePullsPage')
            ->with($this->isInstanceOf(RuntimeAccountData::class), '2', '1795000000000010153')
            ->willReturn($page);

        $pullRepository = $this->createStub(HoyoversePullRepository::class);
        $pullRepository->method('existsByProfileAndPullId')->willReturnCallback(
            static fn (HoyoverseGameProfile $p, string $pullId): bool => $pullId === '1795000000000009953'
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())->method('realign')
            ->with($this->profile, $this->zzzService, '2')
            ->willReturn(new FillerPlan());

        $this->createHandler($bus, $manualPullService, $pullRepository)(
            new SyncPullsPageProfileMessage(self::PROFILE_ID, '2', '1795000000000010153', null, [], $operationId)
        );

        $this->assertSame([$page[0]], $this->persisted);
        $operation = $this->tracker->getOperation($operationId);
        $this->assertSame('completed', $operation['banners']['2']['status']);
        $this->assertSame(1, $operation['imported']);
        $this->assertSame('completed', $operation['status']);
    }

    public function testAccountWithStokenKeepsTheAuthkeyAndGetGachaLog(): void
    {
        $this->setUpProfile(withStoken: true);

        $this->cookieUtils->expects($this->once())->method('generateAuthKey')
            ->with($this->profile, 'webview_gacha')
            ->willReturn('fresh-authkey');
        $this->zzzService->expects($this->never())->method('fetchChroniclePullsPage');
        $this->zzzService->expects($this->once())->method('fetchPullsPage')
            ->with($this->profile, '2', 'fresh-authkey', '0', [], 20)
            ->willReturn([]);
        $manualPullService = $this->createMock(ManualPullService::class);
        $manualPullService->expects($this->once())->method('realign')->willReturn(new FillerPlan());

        $this->createHandler($this->createStub(MessageBusInterface::class), $manualPullService)(
            new SyncPullsPageProfileMessage(self::PROFILE_ID, '2', '0', null, [], null)
        );
    }

    public function testAMessageCarryingAnAuthkeyUsesGetGachaLogEvenWithoutStoken(): void
    {
        // PowerShell import: the authkey comes from the URL pasted by the user
        $this->setUpProfile(withStoken: false);

        $this->cookieUtils->expects($this->never())->method('generateAuthKey');
        $this->zzzService->expects($this->never())->method('fetchChroniclePullsPage');
        $this->zzzService->expects($this->once())->method('fetchPullsPage')
            ->with($this->profile, '2', 'powershell-authkey', '0', ['region' => 'prod_gf_eu'], 20)
            ->willReturn([]);
        $manualPullService = $this->createStub(ManualPullService::class);
        $manualPullService->method('realign')->willReturn(new FillerPlan());

        $this->createHandler($this->createStub(MessageBusInterface::class), $manualPullService)(
            new SyncPullsPageProfileMessage(self::PROFILE_ID, '2', '0', 'powershell-authkey', ['region' => 'prod_gf_eu'], null)
        );
    }

    public function testAChronicleErrorFailsTheBannerAndIsRethrownForRetry(): void
    {
        $this->setUpProfile(withStoken: false);
        $operationId = $this->startOperation();

        $error = (new RetrievePullsDataException('API returned error retcode [-100]: Please login'))->setHoyolabRetcode(-100);
        $this->cookieUtils->expects($this->never())->method('generateAuthKey');
        $this->zzzService->expects($this->once())->method('fetchChroniclePullsPage')->willThrowException($error);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        try {
            $this->createHandler($bus, $this->createStub(ManualPullService::class))(
                new SyncPullsPageProfileMessage(self::PROFILE_ID, '2', '0', null, [], $operationId)
            );
            $this->fail('Expected the chronicle error to be rethrown.');
        } catch (RetrievePullsDataException $e) {
            $this->assertSame($error, $e);
        }

        $banner = $this->tracker->getOperation($operationId)['banners']['2'];
        $this->assertSame('failed', $banner['status']);
        $this->assertSame('API returned error retcode [-100]: Please login', $banner['error']);
        $this->assertSame([], $this->persisted);
    }
}
