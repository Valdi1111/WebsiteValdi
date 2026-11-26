<?php

namespace App\Tests\HoyoverseBundle\Command;

use App\HoyoverseBundle\Command\HoyoverseTaskCommand;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Batch\EndgamesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\GiCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\HsrCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\ZzzCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\SyncDiaryPageProfileMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
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
 * hoyoverse:task with the real schedule parameters and game services, a recording bus and an in-memory tracker
 * (the shared flow is in AbstractTaskCommandTest).
 */
class HoyoverseTaskCommandTest extends KernelTestCase
{
    private const int GENSHIN_PROFILE = 1;
    private const int ZENLESS_PROFILE = 2;
    private const int HONKAI_IMPACT_PROFILE = 3;

    /** @var list<Envelope> */
    private array $dispatched = [];

    private PullOperationTracker $tracker;

    private function createTester(): CommandTester
    {
        self::bootKernel();
        $container = static::getContainer();

        $games = [
            GenshinImpactService::GAME_ID => $container->get(GenshinImpactService::class),
            ZenlessZoneZeroService::GAME_ID => $container->get(ZenlessZoneZeroService::class),
            HonkaiImpact3rdService::GAME_ID => $container->get(HonkaiImpact3rdService::class),
        ];

        $profiles = [];
        foreach ([self::GENSHIN_PROFILE => GenshinImpactService::GAME_ID, self::ZENLESS_PROFILE => ZenlessZoneZeroService::GAME_ID, self::HONKAI_IMPACT_PROFILE => HonkaiImpact3rdService::GAME_ID] as $profileId => $gameId) {
            $profile = (new HoyoverseGameProfile())->setGameId($gameId)->setGameName($games[$gameId]->getGameName())->setGameUid('70000000' . $profileId)->setNickname('Player ' . $profileId);
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $profileId);
            $profiles[$profileId] = $profile;
        }
        $repository = $this->createStub(HoyoverseGameProfileRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?HoyoverseGameProfile => $profiles[$id] ?? null);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $envelope = Envelope::wrap($message, $stamps);
            $this->dispatched[] = $envelope;

            return $envelope->with(new SentStamp('App\\Transport\\TestSender', 'hoyoverse'));
        });

        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        return new CommandTester(new HoyoverseTaskCommand(
            $repository,
            $bus,
            $this->tracker,
            new ServiceLocator(array_map(static fn (object $game): \Closure => static fn (): object => $game, $games)),
            $container->getParameter('hoyoverse.tasks'),
            $container->getParameter('hoyoverse.timezones'),
        ));
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('~^\s*// ?~m', '', $tester->getDisplay()));
    }

    public function testCapabilityTaskSkipsTheProfilesOfOtherGames(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'realm_currency', '--profile-id' => [(string) self::GENSHIN_PROFILE, (string) self::ZENLESS_PROFILE]]));
        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(RealmCurrencyCheckProfileMessage::class, $message);
        self::assertSame(self::GENSHIN_PROFILE, $message->getGameProfileId());
        self::assertStringContainsString('Skipped, the task does not apply to their game: #2 (Zenless Zone Zero).', self::display($tester));
    }

    public function testEndgamesReminderQueuesOneBatchPerConfiguredRegion(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'endgames_reminder']));
        $regions = array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            self::assertInstanceOf(EndgamesReminderBatchMessage::class, $message);

            return $message->getTimezone();
        }, $this->dispatched);
        self::assertSame(['SEA', 'EU', 'NA'], $regions);
    }

    public function testDiarySyncOfAProfileQueuesLastMonthPerCurrency(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'sync_diary', '--profile-id' => [(string) self::ZENLESS_PROFILE]]));

        $lastMonth = new \DateTimeImmutable('first day of last month')->format('Y-m');
        $delays = [];
        foreach ($this->dispatched as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SyncDiaryPageProfileMessage::class, $message);
            self::assertSame(self::ZENLESS_PROFILE, $message->getGameProfileId());
            self::assertSame($lastMonth, $message->getPeriod());
            self::assertSame(1, $message->getPage());
            $delays[] = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        }
        self::assertSame([0, 45000], $delays);
    }

    public function testPullsSyncOfAProfileStartsAnSTokenOperationAndStaggersTheBanners(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'sync_pulls', '--profile-id' => [(string) self::GENSHIN_PROFILE, (string) self::HONKAI_IMPACT_PROFILE]]));

        $operation = $this->tracker->getActiveOperationForProfile(self::GENSHIN_PROFILE);
        self::assertNotNull($operation);
        self::assertSame('stoken_sync', $operation['kind']);
        self::assertNull($this->tracker->getActiveOperationForProfile(self::HONKAI_IMPACT_PROFILE));

        $delays = [];
        foreach ($this->dispatched as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            self::assertSame(self::GENSHIN_PROFILE, $message->getGameProfileId());
            self::assertSame($operation['id'], $message->getOperationId());
            $delays[] = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        }
        self::assertNotEmpty($delays);
        self::assertSame(range(0, (\count($delays) - 1) * 15000, 15000), $delays);
        self::assertStringContainsString('Skipped, the task does not apply to their game: #3 (Honkai Impact 3rd).', self::display($tester));
    }

    public function testCatalogUpdatesQueueTheirScheduleMessageAndRefuseProfiles(): void
    {
        $tester = $this->createTester();

        foreach (['gi_catalog_update' => GiCatalogUpdateMessage::class, 'hsr_catalog_update' => HsrCatalogUpdateMessage::class, 'zzz_catalog_update' => ZzzCatalogUpdateMessage::class] as $task => $messageClass) {
            $this->dispatched = [];

            self::assertSame(Command::SUCCESS, $tester->execute(['task' => $task]), $task);
            self::assertCount(1, $this->dispatched);
            self::assertInstanceOf($messageClass, $this->dispatched[0]->getMessage());
            self::assertStringContainsString('queued on hoyoverse', $tester->getDisplay());

            self::assertSame(Command::FAILURE, $tester->execute(['task' => $task, '--profile-id' => [(string) self::GENSHIN_PROFILE]]), $task);
            self::assertCount(1, $this->dispatched);
        }
    }

    public function testBatchOnlyTaskRefusesProfiles(): void
    {
        $tester = $this->createTester();

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'code_redeem', '--profile-id' => [(string) self::GENSHIN_PROFILE]]));
        self::assertSame([], $this->dispatched);
    }
}
