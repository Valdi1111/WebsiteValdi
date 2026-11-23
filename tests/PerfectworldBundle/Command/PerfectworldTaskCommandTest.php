<?php

namespace App\Tests\PerfectworldBundle\Command;

use App\PerfectworldBundle\Command\PerfectworldTaskCommand;
use App\PerfectworldBundle\Message\Batch\NteCatalogUpdateMessage;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * perfectworld:task: the catalog update is its only task (the shared flow is in AbstractTaskCommandTest).
 */
class PerfectworldTaskCommandTest extends TestCase
{
    public function testCatalogUpdateQueuesTheScheduleMessageAndRefusesProfiles(): void
    {
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return Envelope::wrap($message, $stamps)->with(new SentStamp('App\\Transport\\TestSender', 'perfectworld'));
        });

        $tester = new CommandTester(new PerfectworldTaskCommand(
            $this->createStub(PerfectworldGameProfileRepository::class),
            $bus,
            ['catalog_update' => ['enabled' => true, 'message_class' => NteCatalogUpdateMessage::class, 'cron' => '0 6 * * 1', 'regional' => false, 'jitter' => null]],
            [],
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'catalog_update']));
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(NteCatalogUpdateMessage::class, $dispatched[0]);
        self::assertStringContainsString('queued on perfectworld', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'catalog_update', '--profile-id' => ['1']]));
        self::assertCount(1, $dispatched);
    }
}
