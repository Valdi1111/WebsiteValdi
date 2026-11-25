<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Command\GamesStatusCommand;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
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
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class GamesStatusCommandTest extends TestCase
{
    private User $user;
    private FakeNotesGameService $service;

    protected function setUp(): void
    {
        $this->user = GameProfileRegistryTest::user(1);
        $this->service = new FakeNotesGameService(new MockClock('2026-11-22 10:00:00')->now());
    }

    /**
     * @param list<ProfileSectionInterface> $extraSections
     */
    private function tester(array $extraSections = []): CommandTester
    {
        $clock = new MockClock('2026-11-22 10:00:00');
        $factory = new GameProfileEmbedFactory($clock);

        $provider = new FakeProfileProvider();
        $provider->services = ['fake_game' => $this->service];
        $provider->profiles = [FakeGameProfile::ownedBy($this->user, 1)];

        $resolver = $this->createStub(UserRoleResolver::class);
        $resolver->method('getReachableRoleNames')->willReturn(['ROLE_FAKE']);
        $registry = new GameProfileRegistry([$provider], $resolver, ['fake' => 'ROLE_FAKE']);

        $collector = new GameProfileStatusCollector($registry, [
            new StaminaSection(new StaminaPresenter($factory, new DurationFormatter()), new DurationFormatter(), $clock),
            new DailiesSection(new DailiesPresenter($factory)),
            ...$extraSections,
        ], new NullLogger());

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (mixed $id): ?User => $id === 1 ? $this->user : null);
        $users->method('findOneBy')->willReturnCallback(fn (array $criteria): ?User => $criteria === ['email' => 'user1@example.test'] ? $this->user : null);

        return new CommandTester(new GamesStatusCommand($users, $registry, $collector, new ProfileSummaryPresenter($factory, new DurationFormatter())));
    }

    public function testSummaryOfEveryProfile(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => 'User1@example.test']));
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Fake Game - Status', $output);
        $this->assertStringContainsString('120/200 · full in 1 hr', $output);
        $this->assertStringContainsString('🔔 Daily Tasks 2/4', $output);
        $this->assertSame([false], $this->service->notesCalls);
    }

    public function testFullSectionsAndFreshRead(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', '--full' => true, '--fresh' => true, '--section' => ['stamina']]));
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Fake Game - Stamina', $output);
        $this->assertStringNotContainsString('Dailies', $output);
        $this->assertSame([true], $this->service->notesCalls);
    }

    public function testProfilesByGameOrReference(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', '--game' => 'FAKE']));
        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', '--ref' => ['fake:1']]));
        $this->assertStringContainsString('Fake Game - Status', $tester->getDisplay());
    }

    public function testInvalidInput(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['user' => 'nobody@example.test']));
        $this->assertStringContainsString('No user "nobody@example.test"', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', '--section' => ['pity']]));
        $this->assertStringContainsString('Sections: stamina, dailies', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', '--game' => 'hsr']));
        $this->assertStringContainsString('Games: fg', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', '--ref' => ['fake:99']]));
        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', '--ref' => ['nonsense']]));
    }

    public function testErrorsAreShownOnlyWhenVerbose(): void
    {
        $this->service->notesError = new \RuntimeException('Please login (retcode -100)');
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '1']));
        $this->assertStringContainsString('Unavailable right now', $tester->getDisplay());
        $this->assertStringNotContainsString('retcode -100', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '1'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        $this->assertStringContainsString('fake:1 stamina: RuntimeException: Please login (retcode -100)', $tester->getDisplay());
    }

    public function testSectionsOutOfTheSummaryOnlyOnRequest(): void
    {
        $costly = new class implements ProfileSectionInterface {
            public int $reads = 0;

            public function getKey(): string
            {
                return 'costly';
            }

            public function getLabel(): string
            {
                return 'Costly';
            }

            public function getIcon(): string
            {
                return '⚔️';
            }

            public function supports(GameProfileContext $context): bool
            {
                return true;
            }

            public function usesNotes(): bool
            {
                return false;
            }

            public function inSummary(): bool
            {
                return false;
            }

            public function collect(GameProfileContext $context): object
            {
                $this->reads++;

                return new \stdClass();
            }

            public function present(GameProfileInterface $profile, object $data): UniversalEmbed
            {
                return new UniversalEmbed()->setTitle('Costly view')->addField('Battle', 'won', icon: '⚔️');
            }

            public function summarize(GameProfileInterface $profile, object $data): SummaryLine
            {
                return new SummaryLine('Costly', 'read', '⚔️');
            }
        };
        $tester = $this->tester([$costly]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1']));
        $this->assertStringNotContainsString('Costly', $tester->getDisplay());
        $this->assertSame(0, $costly->reads);

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', '--section' => ['costly'], '--full' => true]));
        $this->assertSame(1, $costly->reads);
        // Labels lose U+FE0F, which the console tables would count one column narrow
        $this->assertStringContainsString("\u{2694} Battle", $tester->getDisplay());
        $this->assertStringNotContainsString("\u{FE0F}", $tester->getDisplay());
    }

    public function testProfilesWithoutTheSectionsAskedAreLeftOut(): void
    {
        $unsupported = new class implements ProfileSectionInterface {
            public function getKey(): string
            {
                return 'elsewhere';
            }

            public function getLabel(): string
            {
                return 'Elsewhere';
            }

            public function getIcon(): string
            {
                return '🚫';
            }

            public function supports(GameProfileContext $context): bool
            {
                return false;
            }

            public function usesNotes(): bool
            {
                return false;
            }

            public function inSummary(): bool
            {
                return false;
            }

            public function collect(GameProfileContext $context): object
            {
                throw new \LogicException('not supported');
            }

            public function present(GameProfileInterface $profile, object $data): UniversalEmbed
            {
                return new UniversalEmbed();
            }

            public function summarize(GameProfileInterface $profile, object $data): SummaryLine
            {
                return new SummaryLine('Elsewhere', '', '🚫');
            }
        };
        $tester = $this->tester([$unsupported]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', '--section' => ['elsewhere']]));
        $this->assertStringContainsString('No profile of this user has these sections.', $tester->getDisplay());
        $this->assertStringNotContainsString('Fake Game - Status', $tester->getDisplay());
    }
}
