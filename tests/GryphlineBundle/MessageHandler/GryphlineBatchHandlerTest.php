<?php

namespace App\Tests\GryphlineBundle\MessageHandler;

use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Batch\SkportCheckInBatchMessage;
use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use App\GryphlineBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\MessageHandler\Batch\DailiesReminderBatchHandler;
use App\GryphlineBundle\MessageHandler\Batch\SkportCheckInBatchHandler;
use App\GryphlineBundle\MessageHandler\Batch\StaminaCheckBatchHandler;
use App\GryphlineBundle\MessageHandler\Batch\WeekliesReminderBatchHandler;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class GryphlineBatchHandlerTest extends TestCase
{
    public function testSkportCheckInBatchHandlerDispatchesProfileMessages(): void
    {
        $repo = $this->createMock(GryphlineGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('skportCheckIn', null)
            ->willReturn([
                ['id' => 10, 'gameBiz' => 'endfield'],
                ['id' => 20, 'gameBiz' => 'endfield'],
            ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new SkportCheckInBatchHandler($repo, $bus, new NullLogger());
        $handler(new SkportCheckInBatchMessage());

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(SkportCheckInProfileMessage::class, $dispatched[0]);
        $this->assertSame(10, $dispatched[0]->getGameProfileId());
        $this->assertSame(20, $dispatched[1]->getGameProfileId());
    }

    public function testStaminaCheckBatchHandlerDispatchesProfileMessages(): void
    {
        $repo = $this->createMock(GryphlineGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('staminaCheck', null)
            ->willReturn([
                ['id' => 15, 'gameBiz' => 'endfield'],
            ]);

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
        $this->assertSame(15, $dispatched[0]->getGameProfileId());
    }

    public function testDailiesReminderBatchHandlerDispatchesRegionalProfileMessages(): void
    {
        $repo = $this->createMock(GryphlineGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('dailiesCheck', null, 'America/New_York')
            ->willReturn([
                ['id' => 30, 'gameBiz' => 'endfield'],
            ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new DailiesReminderBatchHandler($repo, $bus, new NullLogger());
        $handler(new DailiesReminderBatchMessage('America/New_York'));

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(DailiesReminderProfileMessage::class, $dispatched[0]);
        $this->assertSame(30, $dispatched[0]->getGameProfileId());
    }

    public function testWeekliesReminderBatchHandlerDispatchesRegionalProfileMessages(): void
    {
        $repo = $this->createMock(GryphlineGameProfileRepository::class);
        $repo->expects($this->once())
            ->method('findEligibleProfileIds')
            ->with('weekliesCheck', null, 'Europe/Rome')
            ->willReturn([
                ['id' => 40, 'gameBiz' => 'endfield'],
            ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched[] = $msg;
                return new Envelope($msg);
            });

        $handler = new WeekliesReminderBatchHandler($repo, $bus, new NullLogger());
        $handler(new WeekliesReminderBatchMessage('Europe/Rome'));

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(WeekliesReminderProfileMessage::class, $dispatched[0]);
        $this->assertSame(40, $dispatched[0]->getGameProfileId());
    }
}
