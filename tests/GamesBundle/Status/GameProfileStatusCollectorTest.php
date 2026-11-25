<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GamesBundle\Status\Section\DailiesSection;
use App\GamesBundle\Status\Section\StaminaSection;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

class GameProfileStatusCollectorTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private FakeProfileProvider $provider;
    private FakeNotesGameService $service;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->service = new FakeNotesGameService(new \DateTimeImmutable('2026-11-22 09:56:00'));
        $this->provider = new FakeProfileProvider();
        $this->provider->services = ['fake_game' => $this->service];
    }

    private function collector(): GameProfileStatusCollector
    {
        $clock = new MockClock(self::NOW);
        $factory = new GameProfileEmbedFactory($clock);
        $registry = new GameProfileRegistry([$this->provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']);
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $message, $context];
            }
        };

        return new GameProfileStatusCollector($registry, [
            new StaminaSection(new StaminaPresenter($factory, new DurationFormatter()), new DurationFormatter(), $clock),
            new DailiesSection(new DailiesPresenter($factory)),
            self::accountSection(),
        ], $logger);
    }

    /**
     * Section that reads no notes and supports every profile.
     */
    private static function accountSection(): ProfileSectionInterface
    {
        return new class implements ProfileSectionInterface {
            public function getKey(): string
            {
                return 'account';
            }

            public function getLabel(): string
            {
                return 'Account';
            }

            public function getIcon(): string
            {
                return '👤';
            }

            public function supports(GameProfileContext $context): bool
            {
                return true;
            }

            public function usesNotes(): bool
            {
                return false;
            }

            public function collect(GameProfileContext $context): object
            {
                return (object) ['ref' => (string) $context->ref];
            }

            public function present(GameProfileInterface $profile, object $data): UniversalEmbed
            {
                return new UniversalEmbed();
            }

            public function summarize(GameProfileInterface $profile, object $data): SummaryLine
            {
                return new SummaryLine('Account', $data->ref, '👤');
            }
        };
    }

    private function profile(int $id = 1, string $gameBiz = 'fake_game'): FakeGameProfile
    {
        $profile = FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), $id, $gameBiz);
        $this->provider->profiles[] = $profile;

        return $profile;
    }

    public function testNotesAreReadOnceForAllTheNotesSections(): void
    {
        [$status] = $this->collector()->collect([$this->profile()]);

        $this->assertSame([false], $this->service->notesCalls);
        $this->assertSame(2, $this->service->getterCalls);
        $this->assertSame(['stamina', 'dailies', 'account'], array_keys($status->sections));
        $this->assertTrue($status->isSuccessful());
        $this->assertSame(120, $status->sections['stamina']->data->getCurrentStamina());
        $this->assertSame('fake:1', (string) $status->ref);
        $this->assertSame('fg', $status->game?->slug);
        $this->assertEquals(new \DateTimeImmutable('2026-11-22 09:56:00'), $status->fetchedAt);
    }

    public function testFreshIsAppliedToTheNotesRead(): void
    {
        $this->collector()->collect([$this->profile()], fresh: true);

        $this->assertSame([true], $this->service->notesCalls);
    }

    public function testFailedNotesFailEveryNotesSectionWithoutMoreCalls(): void
    {
        $error = new \RuntimeException('Please login');
        $this->service->notesError = $error;

        [$status] = $this->collector()->collect([$this->profile()]);

        $this->assertCount(1, $this->service->notesCalls);
        $this->assertSame(0, $this->service->getterCalls);
        $this->assertSame($error, $status->sections['stamina']->error);
        $this->assertSame($error, $status->sections['dailies']->error);
        $this->assertTrue($status->sections['account']->isSuccessful(), 'a section without notes is still read');
        $this->assertNull($status->fetchedAt);
        $this->assertFalse($status->isSuccessful());
        $this->assertCount(1, $this->logs);
        $this->assertSame('fake:1', $this->logs[0][1]['profile']);
    }

    public function testFailedSectionDoesNotStopTheOthers(): void
    {
        $this->service->dailiesError = new \RuntimeException('broken');

        [$status] = $this->collector()->collect([$this->profile()]);

        $this->assertTrue($status->sections['stamina']->isSuccessful());
        $this->assertFalse($status->sections['dailies']->isSuccessful());
        $this->assertTrue($status->sections['account']->isSuccessful());
        $this->assertSame('dailies', $this->logs[0][1]['section']);
    }

    public function testSectionsTheGameDoesNotSupportAreLeftOut(): void
    {
        // A provider without runtime account data (Perfect World): no notes section applies
        $this->provider->withAccountData = false;

        [$status] = $this->collector()->collect([$this->profile()]);

        $this->assertSame(['account'], array_keys($status->sections));
        $this->assertSame([], $this->service->notesCalls);
    }

    public function testProfileThatCannotBeReadDoesNotStopTheOthers(): void
    {
        $statuses = $this->collector()->collect([$this->profile(1, 'gone_game'), $this->profile(2)]);

        $this->assertInstanceOf(\RuntimeException::class, $statuses[0]->error);
        $this->assertSame([], $statuses[0]->sections);
        $this->assertNull($statuses[0]->game);
        $this->assertTrue($statuses[1]->isSuccessful());
        $this->assertSame(['fake:1', 'fake:2'], array_map(static fn (GameProfileStatus $status): string => (string) $status->ref, $statuses));
    }

    public function testSectionsAreReadInDisplayOrderWhateverTheOrderAsked(): void
    {
        [$status] = $this->collector()->collect([$this->profile()], ['account', 'stamina']);

        $this->assertSame(['stamina', 'account'], array_keys($status->sections));
        $this->assertSame(1, $this->service->getterCalls);
    }

    public function testUnknownSectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown section "pity"');

        $this->collector()->collect([$this->profile()], ['stamina', 'pity']);
    }

    public function testAccountHealthComesFromTheProvider(): void
    {
        $this->provider->health = AccountHealth::problem('Log in again.');

        [$status] = $this->collector()->collect([$this->profile()]);

        $this->assertFalse($status->accountHealth->ok);
        $this->assertSame('Log in again.', $status->accountHealth->problem);
    }
}
