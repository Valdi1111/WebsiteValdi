<?php

namespace App\Tests\GamesBundle\Command;

use App\GamesBundle\Command\AbstractTaskCommand;
use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\RegionalTaskMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Task\ManualTask;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * The shared "<provider>:task" flow, through a provider-less subclass and a recording bus.
 */
class AbstractTaskCommandTest extends TestCase
{
    /** @var list<array{message: object, stamps: list<object>}> */
    private array $dispatched = [];

    private function createTester(?\Closure $failWhen = null, ?MessageBusInterface $realBus = null): CommandTester
    {
        $profiles = [];
        foreach ([1 => true, 2 => false] as $id => $staminaCheck) {
            $profile = (new HoyoverseGameProfile())->setGameName('Genshin Impact')->setNickname('Player ' . $id)->setGameUid('70000000' . $id)->setStaminaCheck($staminaCheck);
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $id);
            $profiles[$id] = $profile;
        }
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?GameProfileInterface => $profiles[$id] ?? null);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []) use ($failWhen): Envelope {
            $this->dispatched[] = ['message' => $message, 'stamps' => $stamps];
            $envelope = new Envelope($message, $stamps);
            if ($failWhen !== null && $failWhen($message)) {
                throw new HandlerFailedException($envelope, [new \RuntimeException('Cookie expired')]);
            }
            if ($envelope->last(ReceivedStamp::class) !== null) {
                return $envelope->with(new HandledStamp(null, 'App\\Handler\\TestHandler::__invoke'));
            }

            return $envelope->with(new SentStamp('App\\Transport\\TestSender', 'test_transport'));
        });

        $command = new class($repository, $realBus ?? $bus) extends AbstractTaskCommand {
            public function __construct(
                private readonly ObjectRepository $repository,
                private readonly MessageBusInterface $bus,
            ) {
                parent::__construct('test:task');
            }

            protected function getProviderKey(): string
            {
                return 'test';
            }

            protected function getProviderName(): string
            {
                return 'Test';
            }

            protected function getScheduledTasks(): array
            {
                return [
                    'stamina' => ['enabled' => true, 'message_class' => TestStaminaBatchMessage::class, 'cron' => '*/30 * * * *', 'regional' => false, 'jitter' => null],
                    'dailies_reminder' => ['enabled' => true, 'message_class' => TestRegionalBatchMessage::class, 'cron' => '0 21 * * *', 'regional' => true, 'jitter' => null],
                    'catalog_update' => ['enabled' => false, 'message_class' => TestGlobalMessage::class, 'cron' => '0 6 * * 1', 'regional' => false, 'jitter' => null],
                ];
            }

            protected function getRegions(): array
            {
                return ['EU' => 'Europe/Paris', 'NA' => 'America/New_York'];
            }

            protected function getManualTasks(): array
            {
                return [
                    'stamina' => new ManualTask('Stamina reminder', static fn (GameProfileInterface $profile): array => [new TestProfileMessage((int) $profile->getId())]),
                    'dailies_reminder' => new ManualTask('Dailies reminder', note: 'Notifies only when dailies are left'),
                    'refresh' => new ManualTask('Refresh one profile', static fn (GameProfileInterface $profile): array => [new TestProfileMessage((int) $profile->getId()), new TestProfileMessage(-(int) $profile->getId())]),
                    'staggered' => new ManualTask('Two delayed messages', static fn (GameProfileInterface $profile): array => [new TestProfileMessage((int) $profile->getId()), new Envelope(new TestProfileMessage(-(int) $profile->getId()), [new DelayStamp(15000)])]),
                    'first_only' => new ManualTask('Applies to profile 1 only', static fn (GameProfileInterface $profile): array => $profile->getId() === 1 ? [new TestProfileMessage(1)] : []),
                ];
            }

            protected function getProfileRepository(): ObjectRepository
            {
                return $this->repository;
            }

            protected function getMessageBus(): MessageBusInterface
            {
                return $this->bus;
            }
        };

        return new CommandTester($command);
    }

    private static function display(CommandTester $tester): string
    {
        // Wrapped comment lines start with "//"
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('~^\s*// ?~m', '', $tester->getDisplay()));
    }

    /**
     * @return list<string>
     */
    private function dispatchedClasses(): array
    {
        return array_map(static fn (array $call): string => $call['message']::class, $this->dispatched);
    }

    public function testListShowsTasksSchedulesAndProfileSupport(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--list' => true]));
        $display = self::display($tester);
        self::assertStringContainsString('stamina Stamina reminder */30 * * * * yes', $display);
        self::assertStringContainsString('dailies_reminder Dailies reminder 0 21 * * *', $display);
        self::assertStringContainsString('per region: EU, NA', $display);
        self::assertStringContainsString('Notifies only when dailies are left', $display);
        self::assertStringContainsString('catalog_update TestGlobalMessage disabled', $display);
        self::assertStringContainsString('refresh Refresh one profile per profile only yes', $display);
        self::assertSame([], $this->dispatched);
    }

    public function testWithoutTaskInNonInteractiveModeItListsTheTasks(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Pass a task name to run it, e.g. "php bin/console test:task stamina".', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testBatchTaskQueuesTheScheduleMessage(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'stamina']));
        self::assertSame([TestStaminaBatchMessage::class], $this->dispatchedClasses());
        self::assertSame([], $this->dispatched[0]['stamps']);
        $display = self::display($tester);
        self::assertStringContainsString('TestStaminaBatchMessage every eligible profile queued on test_transport', $display);
        self::assertStringContainsString('php bin/console messenger:consume test_transport', $display);
        self::assertStringContainsString('1 message(s) dispatched.', $display);
    }

    public function testDashesAndCaseAreAcceptedInTheTaskName(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'Dailies-Reminder', '--region' => ['NA']]));
        self::assertSame(['NA'], array_map(static fn (array $call): string => $call['message']->getTimezone(), $this->dispatched));
    }

    public function testRegionalTaskQueuesOneMessagePerRegion(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'dailies_reminder']));
        self::assertSame(['EU', 'NA'], array_map(static fn (array $call): string => $call['message']->getTimezone(), $this->dispatched));
        self::assertStringContainsString('eligible profiles of region NA', self::display($tester));
    }

    public function testUnknownRegionFailsWithoutDispatching(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'dailies_reminder', '--region' => ['EU', 'ASIA']]));
        self::assertStringContainsString('Unknown region(s): ASIA. Regions: EU, NA.', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testRegionOnAGlobalTaskFails(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'stamina', '--region' => ['EU']]));
        self::assertStringContainsString('The "stamina" task is not regional: --region does not apply.', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testUnknownTaskSuggestsTheClosestOnes(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'stamnia']));
        self::assertStringContainsString('Unknown Test task "stamnia". Did you mean: stamina?', self::display($tester));

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'daily']));
        self::assertStringContainsString('Did you mean: dailies_reminder?', self::display($tester));

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'xyz']));
        self::assertStringContainsString('Unknown Test task "xyz". Run with --list to see the tasks.', self::display($tester));
    }

    public function testDisabledTaskIsDispatchedWithANote(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'catalog_update']));
        self::assertSame([TestGlobalMessage::class], $this->dispatchedClasses());
        self::assertStringContainsString('This task is disabled in the schedule: it is dispatched anyway.', self::display($tester));
    }

    public function testProfilesGetTheirOwnMessagesAndShowTheFeatureFlag(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'stamina', '--profile-id' => ['1', '2', '1']]));
        self::assertSame([1, 2], array_map(static fn (array $call): int => $call['message']->profileId, $this->dispatched));
        $display = self::display($tester);
        self::assertStringContainsString('ID Game Nickname UID staminaCheck', $display);
        self::assertStringContainsString('1 Genshin Impact Player 1 700000001 on', $display);
        self::assertStringContainsString('2 Genshin Impact Player 2 700000002 off (skipped by the batch)', $display);
        self::assertStringContainsString('TestProfileMessage profile #2 queued on test_transport', $display);
    }

    public function testUnknownProfileFailsBeforeDispatchingAnything(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'stamina', '--profile-id' => ['1', '9', 'abc']]));
        self::assertStringContainsString('Test game profile(s) not found: 9, abc.', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testBatchOnlyTaskRefusesProfiles(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'dailies_reminder', '--profile-id' => ['1']]));
        self::assertStringContainsString('The "dailies_reminder" task only runs for every eligible profile: drop --profile-id.', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testProfileOnlyTaskNeedsProfiles(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'refresh']));
        self::assertStringContainsString('The "refresh" task runs per profile only: pass --profile-id.', self::display($tester));

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'refresh', '--profile-id' => ['2']]));
        self::assertSame([2, -2], array_map(static fn (array $call): int => $call['message']->profileId, $this->dispatched));
    }

    public function testProfilesTheTaskDoesNotApplyToAreReportedAsSkipped(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'first_only', '--profile-id' => ['1', '2']]));
        self::assertSame([1], array_map(static fn (array $call): int => $call['message']->profileId, $this->dispatched));
        self::assertStringContainsString('Skipped, the task does not apply to their game: #2 (Genshin Impact).', self::display($tester));

        $this->dispatched = [];
        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'first_only', '--profile-id' => ['2']]));
        self::assertSame([], $this->dispatched);
        self::assertStringContainsString('Nothing dispatched.', self::display($tester));
    }

    public function testRegionWithProfilesFails(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'stamina', '--profile-id' => ['1'], '--region' => ['EU']]));
        self::assertStringContainsString('--region only applies without --profile-id', self::display($tester));
        self::assertSame([], $this->dispatched);
    }

    public function testSyncHandlesInThisProcessAndReportsHandlerFailures(): void
    {
        $tester = $this->createTester(static fn (object $message): bool => $message instanceof TestProfileMessage && $message->profileId === 2);

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'stamina', '--profile-id' => ['1', '2'], '--sync' => true]));
        self::assertCount(2, $this->dispatched);
        foreach ($this->dispatched as $call) {
            self::assertCount(1, $call['stamps']);
            self::assertInstanceOf(ReceivedStamp::class, $call['stamps'][0]);
            self::assertSame('test_profile', $call['stamps'][0]->getTransportName());
        }
        $display = self::display($tester);
        self::assertStringContainsString('TestProfileMessage profile #1 handled by TestHandler', $display);
        self::assertStringContainsString('TestProfileMessage profile #2 failed', $display);
        self::assertStringContainsString('1 of 2 message(s) failed.', $display);
        self::assertStringContainsString('TestProfileMessage (profile #2): RuntimeException: Cookie expired', $display);
        self::assertStringNotContainsString('messenger:consume', $display);
    }

    public function testSyncBatchExplainsThatProfileMessagesAreStillQueued(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'stamina', '--sync' => true]));
        self::assertInstanceOf(ReceivedStamp::class, $this->dispatched[0]['stamps'][0]);
        self::assertSame('test', $this->dispatched[0]['stamps'][0]->getTransportName());
        $display = self::display($tester);
        self::assertStringContainsString('The batch handlers ran here; the per-profile messages they dispatched, and the notifications, are queued for the workers.', $display);
        self::assertStringContainsString('1 message(s) handled.', $display);
    }

    public function testSyncRunsTheHandlerOnARealBusInsteadOfTheTransport(): void
    {
        $transport = new InMemoryTransport();
        $handled = [];
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([TestProfileMessage::class => ['test_profile']], new ServiceLocator(['test_profile' => static fn (): InMemoryTransport => $transport]))),
            new HandleMessageMiddleware(new HandlersLocator([TestProfileMessage::class => [static function (TestProfileMessage $message) use (&$handled): void {
                $handled[] = $message->profileId;
            }]])),
        ]);

        $tester = $this->createTester(realBus: $bus);
        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'staggered', '--profile-id' => ['1']]));
        self::assertSame([], $handled);
        self::assertSame([1, -1], array_map(static fn (Envelope $envelope): int => $envelope->getMessage()->profileId, $transport->getSent()));
        self::assertSame(15000, $transport->getSent()[1]->last(DelayStamp::class)?->getDelay());
        self::assertStringContainsString('TestProfileMessage profile #1 queued on test_profile', self::display($tester));

        $transport->reset();
        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'staggered', '--profile-id' => ['1'], '--sync' => true]));
        self::assertSame([1, -1], $handled);
        self::assertSame([], $transport->getSent());
        $display = self::display($tester);
        self::assertStringContainsString('TestProfileMessage profile #1 handled by Closure', $display);
        self::assertStringContainsString('--sync ignores the delays between these messages: they ran one after the other.', $display);
    }

    public function testInteractivePickAsksForProfilesAndConfirms(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['stamina', '1, 2', 'yes']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([1, 2], array_map(static fn (array $call): int => $call['message']->profileId, $this->dispatched));
        self::assertStringContainsString('Which Test task do you want to run?', self::display($tester));
    }

    public function testInteractiveBatchCanBeCancelled(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['stamina', '', 'no']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->dispatched);
        self::assertStringContainsString('Nothing dispatched.', self::display($tester));
    }
}

class TestStaminaBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'staminaCheck';
    }
}

class TestRegionalBatchMessage implements RegionalTaskMessageInterface
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}

class TestGlobalMessage implements TaskMessageInterface
{
}

#[AsMessage('test_profile')]
class TestProfileMessage
{
    public function __construct(public readonly int $profileId)
    {
    }
}
