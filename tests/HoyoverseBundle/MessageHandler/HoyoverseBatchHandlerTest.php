<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\Batch\CodeRedeemBatchMessage;
use App\HoyoverseBundle\Message\Batch\DailiesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\ExpeditionsCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\HoyolabCheckInBatchMessage;
use App\HoyoverseBundle\Message\Batch\RealmCurrencyCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\ShopStatusCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\StaminaCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\HoyoverseBundle\Message\Profile\CodeRedeemProfileMessage;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\ExpeditionsCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Batch\CodeRedeemBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\DailiesReminderBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\ExpeditionsCheckBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\HoyolabCheckInBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\RealmCurrencyCheckBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\ShopStatusCheckBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\StaminaCheckBatchHandler;
use App\HoyoverseBundle\MessageHandler\Batch\WeekliesReminderBatchHandler;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class HoyoverseBatchHandlerTest extends TestCase
{
    public function testHoyolabCheckInBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('hoyolabCheckIn', null, null)
            ->willReturn([['id' => 1, 'gameId' => 2]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new HoyolabCheckInBatchHandler($repo, $bus, new NullLogger());
        $handler(new HoyolabCheckInBatchMessage());

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(HoyolabCheckInProfileMessage::class, $dispatched[0]);
        $this->assertSame(1, $dispatched[0]->getGameProfileId());
    }

    public function testStaminaCheckBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('staminaCheck', null, null)
            ->willReturn([['id' => 2, 'gameId' => 1]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new StaminaCheckBatchHandler($repo, $bus, new NullLogger());
        $handler(new StaminaCheckBatchMessage());

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(StaminaCheckProfileMessage::class, $dispatched[0]);
        $this->assertSame(2, $dispatched[0]->getGameProfileId());
    }

    public function testDailiesReminderBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('dailiesCheck', null, 'Asia/Shanghai')
            ->willReturn([['id' => 3, 'gameId' => 8]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new DailiesReminderBatchHandler($repo, $bus, new NullLogger());
        $handler(new DailiesReminderBatchMessage('Asia/Shanghai'));

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(DailiesReminderProfileMessage::class, $dispatched[0]);
        $this->assertSame(3, $dispatched[0]->getGameProfileId());
    }

    public function testWeekliesReminderBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('weekliesCheck', null, 'Europe/Berlin')
            ->willReturn([['id' => 4, 'gameId' => 1]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new WeekliesReminderBatchHandler($repo, $bus, new NullLogger());
        $handler(new WeekliesReminderBatchMessage('Europe/Berlin'));

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(WeekliesReminderProfileMessage::class, $dispatched[0]);
        $this->assertSame(4, $dispatched[0]->getGameProfileId());
    }

    public function testExpeditionsCheckBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('expeditionsCheck', null, null)
            ->willReturn([['id' => 5, 'gameId' => 2]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new ExpeditionsCheckBatchHandler($repo, $bus, new NullLogger());
        $handler(new ExpeditionsCheckBatchMessage());

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(ExpeditionsCheckProfileMessage::class, $dispatched[0]);
    }

    public function testRealmCurrencyCheckBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('realmCurrencyCheck', null, null)
            ->willReturn([['id' => 6, 'gameId' => 2]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new RealmCurrencyCheckBatchHandler($repo, $bus, new NullLogger());
        $handler(new RealmCurrencyCheckBatchMessage());

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(RealmCurrencyCheckProfileMessage::class, $dispatched[0]);
    }

    public function testShopStatusCheckBatchHandler(): void
    {
        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('shopStatusCheck', null, null)
            ->willReturn([['id' => 7, 'gameId' => 8]]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new ShopStatusCheckBatchHandler($repo, $bus, new NullLogger());
        $handler(new ShopStatusCheckBatchMessage());

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(ShopStatusCheckProfileMessage::class, $dispatched[0]);
    }
}
