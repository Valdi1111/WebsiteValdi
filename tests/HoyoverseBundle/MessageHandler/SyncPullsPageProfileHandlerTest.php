<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\HoyolabException;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyntheticReconcilerManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SyncPullsPageProfileHandlerTest extends TestCase
{
    public function testAnAuthkeyGenerationErrorFailsTheBannerAndIsRethrownForRetry(): void
    {
        $profile = (new HoyoverseGameProfile())
            ->setAccount((new HoyoverseAccount())->setCookie('ltoken_v2=abc; ltuid_v2=123'))
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameBiz(GenshinImpactService::GAME_BIZ)
            ->setNickname('Traveler');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);

        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $operationId = (string) $tracker->startSyncOperation(10, 1, PullOperationKind::STOKEN_SYNC, ['301' => 'Character Event Wish'])['id'];

        $error = new HoyolabException('Failed to generate authkey for profile Traveler: Please login (retcode: -100)');
        $cookieUtils = $this->createMock(HoyolabCookieUtilsService::class);
        $cookieUtils->expects($this->once())->method('generateAuthKey')->willThrowException($error);

        $giService = $this->getMockBuilder(GenshinImpactService::class)
            ->setConstructorArgs([new NullLogger()])
            ->onlyMethods(['fetchPullsPage'])
            ->getMock();
        $giService->expects($this->never())->method('fetchPullsPage');

        $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = new SyncPullsPageProfileHandler(
            $profileRepo,
            $cookieUtils,
            new ServiceLocator([GenshinImpactService::GAME_ID => static fn () => $giService]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
        );
        $handler->setEntityManager($this->createStub(EntityManagerInterface::class));
        $handler->setPullRepository($this->createStub(HoyoversePullRepository::class));
        $handler->setBus($bus);
        $handler->setSyntheticReconcilerManager(new PullSyntheticReconcilerManager([]));
        $handler->setOperationTracker($tracker);
        $handler->setLockFactory(new LockFactory(new InMemoryStore()));
        $handler->setManualPullService($this->createStub(ManualPullService::class));

        try {
            $handler(new SyncPullsPageProfileMessage(10, '301', '0', null, [], $operationId));
            $this->fail('Expected the authkey generation error to be rethrown.');
        } catch (HoyolabException $e) {
            $this->assertSame($error, $e);
        }

        $banner = $tracker->getOperation($operationId)['banners']['301'];
        $this->assertSame('failed', $banner['status']);
        $this->assertSame('Failed to generate authkey for profile Traveler: Please login (retcode: -100)', $banner['error']);
    }
}
