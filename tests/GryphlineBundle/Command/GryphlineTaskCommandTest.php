<?php

namespace App\Tests\GryphlineBundle\Command;

use App\GryphlineBundle\Command\GryphlineTaskCommand;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * gryphline:task with the real schedule parameters and Endfield service, a recording bus and an in-memory tracker
 * (the shared flow is in AbstractTaskCommandTest).
 */
class GryphlineTaskCommandTest extends KernelTestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    private PullOperationTracker $tracker;

    private ArknightsEndfieldService $endfield;

    private function createTester(): CommandTester
    {
        self::bootKernel();
        $container = static::getContainer();

        $profile = (new GryphlineGameProfile())->setGameId(3)->setGameBiz(ArknightsEndfieldService::GAME_BIZ)->setGameName('Arknights: Endfield')->setGameUid('1234567')->setServerId('2')->setNickname('Endmin');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 7);
        $repository = $this->createStub(GryphlineGameProfileRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?GryphlineGameProfile => $id === 7 ? $profile : null);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $envelope = Envelope::wrap($message, $stamps);
            $this->dispatched[] = $envelope;

            return $envelope->with(new SentStamp('App\\Transport\\TestSender', 'gryphline'));
        });

        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
        $endfield = $this->endfield = $container->get(ArknightsEndfieldService::class);

        return new CommandTester(new GryphlineTaskCommand(
            $repository,
            $bus,
            $this->tracker,
            new ServiceLocator([ArknightsEndfieldService::GAME_BIZ => static fn (): ArknightsEndfieldService => $endfield]),
            $container->getParameter('gryphline.tasks'),
            $container->getParameter('gryphline.timezones'),
        ));
    }

    public function testCheckInForAProfileQueuesItsProfileMessage(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'skport_check_in', '--profile-id' => ['7']]));
        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(SkportCheckInProfileMessage::class, $message);
        self::assertSame(7, $message->getGameProfileId());
    }

    public function testRegionalReminderQueuesOneBatchPerConfiguredRegion(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'dailies_reminder']));
        $regions = array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            self::assertInstanceOf(DailiesReminderBatchMessage::class, $message);

            return $message->getTimezone();
        }, $this->dispatched);
        self::assertSame(['US_EU', 'SEA'], $regions);
    }

    public function testPullsSyncForAProfileStartsAnOperationAndStaggersTheBanners(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'sync_pulls', '--profile-id' => ['7']]));

        $operation = $this->tracker->getActiveOperationForProfile(7);
        self::assertNotNull($operation);
        self::assertSame('auto_sync', $operation['kind']);

        $categories = [];
        $delays = [];
        foreach ($this->dispatched as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            self::assertSame(7, $message->getGameProfileId());
            self::assertSame($operation['id'], $message->getOperationId());
            $categories[] = $message->getCategory();
            $delays[] = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        }
        // One message per sync stream: the weapon stream feeds both weapon banners
        self::assertSame(array_map('strval', array_keys($this->endfield->getSyncStreams())), $categories);
        self::assertCount(6, $categories);
        self::assertSame([0, 15000, 30000, 45000, 60000, 75000], $delays);
    }
}
