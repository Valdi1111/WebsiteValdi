<?php

namespace App\Tests\GamesBundle\Command;

use App\GamesBundle\Command\AbstractFetchGameProfilesCommand;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shared "<provider>:fetch-game-profiles" flow, through a provider-less subclass.
 */
class AbstractFetchGameProfilesCommandTest extends TestCase
{
    /** @var list<int|null> Ids of the accounts passed to fetchGameProfiles(), in order */
    private array $fetched = [];

    /**
     * @param list<GameAccountInterface> $accounts
     * @param \Closure(GameAccountInterface): array<GameProfileInterface> $fetch
     * @param array<string, callable(GameProfileInterface): mixed> $extraColumns
     */
    private function createTester(array $accounts, \Closure $fetch, array $extraColumns = []): CommandTester
    {
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(static function (mixed $id) use ($accounts): ?GameAccountInterface {
            foreach ($accounts as $account) {
                if ($account->getId() === $id) {
                    return $account;
                }
            }

            return null;
        });
        $repository->method('findAll')->willReturn($accounts);

        $recordingFetch = function (GameAccountInterface $account) use ($fetch): array {
            $this->fetched[] = $account->getId();

            return $fetch($account);
        };

        $command = new class($repository, $recordingFetch, $extraColumns) extends AbstractFetchGameProfilesCommand {
            public function __construct(
                private readonly ObjectRepository $repository,
                private readonly \Closure $fetch,
                private readonly array $extraColumns,
            ) {
                parent::__construct('test:fetch-game-profiles');
            }

            protected function getProviderName(): string
            {
                return 'Test';
            }

            protected function getAccountRepository(): ObjectRepository
            {
                return $this->repository;
            }

            protected function fetchGameProfiles(GameAccountInterface $account): array
            {
                return ($this->fetch)($account);
            }

            protected function getExtraColumns(): array
            {
                return $this->extraColumns;
            }
        };

        return new CommandTester($command);
    }

    private function createAccount(int $id): GameAccountInterface
    {
        return $this->createConfiguredStub(GameAccountInterface::class, ['getId' => $id]);
    }

    private function createProfile(int $id, string $nickname, ?int $level = 60): GameProfileInterface
    {
        return $this->createConfiguredStub(GameProfileInterface::class, [
            'getId' => $id,
            'getGameName' => 'Test Game',
            'getGameUid' => (string) (800000000 + $id),
            'getNickname' => $nickname,
            'getLevel' => $level,
            'getParsedRegion' => 'asia',
            'getParsedTimezone' => 'Asia/Shanghai',
        ]);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testOneAccountFetchesOnlyThatAccountAndPrintsItsProfiles(): void
    {
        $tester = $this->createTester(
            [$this->createAccount(1), $this->createAccount(2)],
            fn (GameAccountInterface $account): array => [$this->createProfile(10, 'Traveler'), $this->createProfile(11, 'Trailblazer')],
        );

        $exitCode = $tester->execute(['accountId' => '2']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([2], $this->fetched);
        $display = self::display($tester);
        self::assertStringContainsString('Fetching the game profiles of 1 Test account(s)', $display);
        self::assertStringContainsString('Account #2 has 2 game profile(s).', $display);
        self::assertStringContainsString(' 10 Test Game 800000010 Traveler 60 asia Asia/Shanghai ', $display);
        self::assertStringContainsString('Trailblazer', $display);
    }

    public function testUnknownAccountFailsWithoutFetching(): void
    {
        $tester = $this->createTester([$this->createAccount(1)], static fn (): array => []);

        $exitCode = $tester->execute(['accountId' => '9']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $this->fetched);
        self::assertStringContainsString('Test account #9 not found.', self::display($tester));
    }

    public function testNoAccountsWarnsAndSucceeds(): void
    {
        $tester = $this->createTester([], static fn (): array => []);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->fetched);
        self::assertStringContainsString('No Test accounts found.', self::display($tester));
    }

    public function testEveryAccountRunsAfterAFailureAndTheExitCodeReportsIt(): void
    {
        $tester = $this->createTester(
            [$this->createAccount(1), $this->createAccount(2), $this->createAccount(3)],
            function (GameAccountInterface $account): array {
                if ($account->getId() === 2) {
                    throw new \RuntimeException('Cookie expired');
                }

                return [$this->createProfile($account->getId() * 10, 'Profile of ' . $account->getId())];
            },
        );

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([1, 2, 3], $this->fetched);
        $display = self::display($tester);
        self::assertStringContainsString('Fetching the game profiles of 3 Test account(s)', $display);
        self::assertStringContainsString('Failed to fetch the game profiles of account #2: Cookie expired', $display);
        self::assertStringContainsString('Profile of 1', $display);
        self::assertStringContainsString('Profile of 3', $display);
        self::assertStringContainsString('The fetch failed for 1 of 3 account(s).', $display);
    }

    public function testAccountWithoutProfilesWarnsAndSucceeds(): void
    {
        $tester = $this->createTester([$this->createAccount(1)], static fn (): array => []);

        $exitCode = $tester->execute(['accountId' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Account #1 has no game profiles.', self::display($tester));
    }

    public function testExtraColumnsAreAppendedAndMissingValuesShownAsNa(): void
    {
        $tester = $this->createTester(
            [$this->createAccount(1)],
            fn (): array => ['first' => $this->createProfile(10, 'Traveler', null)],
            ['Server' => static fn (GameProfileInterface $profile): string => 'Asia-' . $profile->getId(), 'Note' => static fn (): ?string => null],
        );

        $exitCode = $tester->execute(['accountId' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = self::display($tester);
        self::assertStringContainsString(' ID Game UID Nickname Level Region Timezone Server Note ', $display);
        self::assertStringContainsString(' 10 Test Game 800000010 Traveler N/A asia Asia/Shanghai Asia-10 N/A ', $display);
    }
}
