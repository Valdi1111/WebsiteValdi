<?php

namespace App\Tests\PerfectworldBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use App\PerfectworldBundle\Status\PerfectworldProfileProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Perfect World adapter of the profile status layer, around stubbed repositories and game services (no database).
 */
class PerfectworldProfileProviderTest extends TestCase
{
    private const string ICON = 'https://example.com/nte.png';
    private const string NOW = '2026-11-25 12:00:00 UTC';

    /**
     * @param list<PerfectworldAccount>    $accounts accounts the account repository returns, in that order
     * @param array<string, GameInterface> $games    game services by game biz (default: an NTE stub)
     */
    private function createProvider(
        array $accounts = [],
        ?PerfectworldGameProfile $found = null,
        ?array $games = null,
        ?PerfectworldAccountRepository $accountRepository = null,
    ): PerfectworldProfileProvider {
        if ($accountRepository === null) {
            $accountRepository = $this->createStub(PerfectworldAccountRepository::class);
            $accountRepository->method('findBy')->willReturn($accounts);
        }

        $profileRepository = $this->createStub(PerfectworldGameProfileRepository::class);
        $profileRepository->method('find')->willReturn($found);

        $games ??= ['nte' => $this->createGameService('Neverness to Everness', self::ICON)];

        return new PerfectworldProfileProvider(
            $accountRepository,
            $profileRepository,
            new ServiceLocator(array_map(static fn (GameInterface $game): \Closure => static fn (): GameInterface => $game, $games)),
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    private function createGameService(string $name, ?string $icon): GameInterface
    {
        $game = $this->createStub(GameInterface::class);
        $game->method('getGameName')->willReturn($name);
        $game->method('getIcon')->willReturn($icon);

        return $game;
    }

    private static function createUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * @param list<PerfectworldGameProfile> $profiles
     */
    private static function createAccount(int $id, User $user, array $profiles = []): PerfectworldAccount
    {
        $account = (new PerfectworldAccount())->setUser($user);
        (new \ReflectionProperty(PerfectworldAccount::class, 'id'))->setValue($account, $id);
        foreach ($profiles as $profile) {
            $account->addGameProfile($profile);
        }

        return $account;
    }

    private static function createProfile(?int $id, bool $active = true, string $gameBiz = 'nte'): PerfectworldGameProfile
    {
        $profile = (new PerfectworldGameProfile())->setActive($active)->setGameBiz($gameBiz)->setGameUid('5550' . $id);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    /**
     * @param list<PerfectworldGameProfile> $profiles
     * @return list<int|null>
     */
    private static function ids(array $profiles): array
    {
        return array_map(static fn (PerfectworldGameProfile $profile): ?int => $profile->getId(), $profiles);
    }

    public function testKeyAndProfileClass(): void
    {
        $provider = $this->createProvider();

        self::assertSame('perfectworld', $provider->getKey());
        self::assertSame(PerfectworldGameProfile::class, $provider->getProfileClass());
    }

    public function testFindProfilesOfReturnsActiveProfilesByAccountThenProfileId(): void
    {
        $user = self::createUser(1);
        // Out of order on purpose: the provider must sort, not rely on the repository
        $accounts = [
            self::createAccount(2, $user, [self::createProfile(5), self::createProfile(3, active: false), self::createProfile(4)]),
            self::createAccount(1, $user, [self::createProfile(9), self::createProfile(2)]),
        ];

        $accountRepository = $this->createMock(PerfectworldAccountRepository::class);
        $accountRepository->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user], ['id' => 'ASC'])
            ->willReturn($accounts);

        $profiles = $this->createProvider(accountRepository: $accountRepository)->findProfilesOf($user);

        self::assertSame([2, 9, 4, 5], self::ids($profiles));
    }

    public function testFindProfilesOfWithoutAccounts(): void
    {
        self::assertSame([], $this->createProvider()->findProfilesOf(self::createUser(1)));
    }

    public function testFindProfileOfMatchesTheOwnerById(): void
    {
        $profile = self::createProfile(7);
        self::createAccount(1, self::createUser(1), [$profile]);

        // Another instance of the same user, as a chat request would carry
        $found = $this->createProvider(found: $profile)->findProfileOf(self::createUser(1), 7);

        self::assertSame($profile, $found);
    }

    public function testFindProfileOfRejectsTheProfileOfAnotherUser(): void
    {
        $profile = self::createProfile(7);
        self::createAccount(1, self::createUser(2), [$profile]);

        self::assertNull($this->createProvider(found: $profile)->findProfileOf(self::createUser(1), 7));
    }

    public function testFindProfileOfReturnsNullForAMissingProfile(): void
    {
        self::assertNull($this->createProvider(found: null)->findProfileOf(self::createUser(1), 404));
    }

    public function testDescribeUsesTheSlugMap(): void
    {
        $provider = $this->createProvider();

        $game = $provider->describe(self::createProfile(7));

        self::assertNotNull($game);
        self::assertSame('perfectworld', $game->provider);
        self::assertSame('nte', $game->gameBiz);
        self::assertSame('nte', $game->slug);
        self::assertSame('Neverness to Everness', $game->name);
        self::assertSame(self::ICON, $game->iconUrl);
        self::assertSame(['neverness-to-everness'], $game->aliases);
        self::assertTrue($game->matches('NTE'));
        self::assertSame(['nte'], array_keys($provider->getGames()));
    }

    public function testDescribeFallsBackToASlugFromTheNameForAGameMissingFromTheMap(): void
    {
        $provider = $this->createProvider(games: [
            'nte' => $this->createGameService('Neverness to Everness', self::ICON),
            'pw_next' => $this->createGameService('Perfect World: Next', null),
        ]);

        $game = $provider->describe(self::createProfile(8, gameBiz: 'pw_next'));

        self::assertNotNull($game);
        self::assertSame('pw_next', $game->gameBiz);
        self::assertSame('perfect-world-next', $game->slug);
        self::assertSame([], $game->aliases);
        self::assertNull($game->iconUrl);
    }

    public function testDescribeReturnsNullForAGameWithoutService(): void
    {
        self::assertNull($this->createProvider()->describe(self::createProfile(7, gameBiz: 'unknown')));
    }

    public function testCreateContextHasNoAccountData(): void
    {
        $nte = $this->createGameService('Neverness to Everness', self::ICON);
        $provider = $this->createProvider(games: ['nte' => $nte]);
        $profile = self::createProfile(7);

        $context = $provider->createContext($profile, fresh: true);

        self::assertSame($profile, $context->profile);
        self::assertSame('perfectworld:7', (string) $context->ref);
        self::assertSame('nte', $context->game->slug);
        self::assertSame($nte, $context->gameService);
        self::assertTrue($context->fresh);
        self::assertNull($context->accountData);
    }

    public function testCreateContextThrowsForAnUnknownGame(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No perfectworld game service for game "unknown".');

        $this->createProvider()->createContext(self::createProfile(7, gameBiz: 'unknown'));
    }

    public function testCreateContextThrowsForAProfileWithoutId(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->createProvider()->createContext(self::createProfile(null));
    }

    public function testAccountHealthReportsAnInvalidTokenFirst(): void
    {
        $account = self::createAccount(1, self::createUser(1))
            ->setTokenInvalid(true)
            ->setTokenExpiresAt(new \DateTimeImmutable(self::NOW)->modify('-1 day'));

        $health = $this->createProvider()->getAccountHealth($account);

        self::assertFalse($health->ok);
        self::assertSame('The Perfect World login is no longer valid: log in again on the site.', $health->problem);
    }

    public function testAccountHealthReportsAnExpiredToken(): void
    {
        $account = self::createAccount(1, self::createUser(1))
            ->setTokenExpiresAt(new \DateTimeImmutable(self::NOW)->modify('-1 second'));

        $health = $this->createProvider()->getAccountHealth($account);

        self::assertFalse($health->ok);
        self::assertSame('The Perfect World login has expired: log in again on the site.', $health->problem);
    }

    public function testAccountHealthComparesTheExpiryAsAnInstant(): void
    {
        // 12:30 in Rome is 11:30 UTC: expired at 12:00 UTC, whatever the timezone of the stored value
        $account = self::createAccount(1, self::createUser(1))
            ->setTokenExpiresAt(new \DateTimeImmutable('2026-11-25 12:30:00', new \DateTimeZone('Europe/Rome')));

        self::assertFalse($this->createProvider()->getAccountHealth($account)->ok);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideHealthyExpiries(): iterable
    {
        yield 'unknown expiry' => [null];
        yield 'expires now' => ['+0 seconds'];
        yield 'expires later' => ['+1 second'];
        yield 'expires in 30 days' => ['+30 days'];
    }

    #[DataProvider('provideHealthyExpiries')]
    public function testAccountHealthIsOkBeforeTheExpiry(?string $offset): void
    {
        $account = self::createAccount(1, self::createUser(1))
            ->setTokenExpiresAt($offset !== null ? new \DateTimeImmutable(self::NOW)->modify($offset) : null);

        $health = $this->createProvider()->getAccountHealth($account);

        self::assertTrue($health->ok);
        self::assertNull($health->problem);
    }

    public function testAccountHealthOfAnotherProviderAccountIsOk(): void
    {
        self::assertTrue($this->createProvider()->getAccountHealth($this->createStub(GameAccountInterface::class))->ok);
    }
}
