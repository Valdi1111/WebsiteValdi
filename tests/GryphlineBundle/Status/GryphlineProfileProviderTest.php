<?php

namespace App\Tests\GryphlineBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Status\GryphlineProfileProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Gryphline adapter of the profile status layer, around stubbed repositories and game services (no database).
 */
class GryphlineProfileProviderTest extends TestCase
{
    private const string ICON = 'https://example.com/endfield.png';

    /**
     * @param list<GryphlineAccount>       $accounts accounts the account repository returns, in that order
     * @param array<string, GameInterface> $games    game services by game biz (default: an Endfield stub)
     */
    private function createProvider(
        array $accounts = [],
        ?GryphlineGameProfile $found = null,
        ?array $games = null,
        ?GryphlineAccountRepository $accountRepository = null,
    ): GryphlineProfileProvider {
        if ($accountRepository === null) {
            $accountRepository = $this->createStub(GryphlineAccountRepository::class);
            $accountRepository->method('findBy')->willReturn($accounts);
        }

        $profileRepository = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepository->method('find')->willReturn($found);

        $games ??= ['endfield' => $this->createGameService('Arknights: Endfield', self::ICON)];

        return new GryphlineProfileProvider(
            $accountRepository,
            $profileRepository,
            new ServiceLocator(array_map(static fn (GameInterface $game): \Closure => static fn (): GameInterface => $game, $games)),
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
     * @param list<GryphlineGameProfile> $profiles
     */
    private static function createAccount(int $id, User $user, array $profiles = []): GryphlineAccount
    {
        $account = (new GryphlineAccount())->setUser($user);
        (new \ReflectionProperty(GryphlineAccount::class, 'id'))->setValue($account, $id);
        foreach ($profiles as $profile) {
            $account->addGameProfile($profile);
        }

        return $account;
    }

    private static function createProfile(?int $id, bool $active = true, string $gameBiz = 'endfield'): GryphlineGameProfile
    {
        $profile = (new GryphlineGameProfile())->setActive($active)->setGameBiz($gameBiz)->setGameUid('1000' . $id);
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    /**
     * @param list<GryphlineGameProfile> $profiles
     * @return list<int|null>
     */
    private static function ids(array $profiles): array
    {
        return array_map(static fn (GryphlineGameProfile $profile): ?int => $profile->getId(), $profiles);
    }

    public function testKeyAndProfileClass(): void
    {
        $provider = $this->createProvider();

        self::assertSame('gryphline', $provider->getKey());
        self::assertSame(GryphlineGameProfile::class, $provider->getProfileClass());
    }

    public function testFindProfilesOfReturnsActiveProfilesByAccountThenProfileId(): void
    {
        $user = self::createUser(1);
        // Out of order on purpose: the provider must sort, not rely on the repository
        $accounts = [
            self::createAccount(2, $user, [self::createProfile(5), self::createProfile(3, active: false), self::createProfile(4)]),
            self::createAccount(1, $user, [self::createProfile(9), self::createProfile(2)]),
        ];

        $accountRepository = $this->createMock(GryphlineAccountRepository::class);
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

    public function testFindProfileOfReturnsInactiveProfilesOfTheUser(): void
    {
        $profile = self::createProfile(7, active: false);
        self::createAccount(1, self::createUser(1), [$profile]);

        self::assertSame($profile, $this->createProvider(found: $profile)->findProfileOf(self::createUser(1), 7));
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
        self::assertSame('gryphline', $game->provider);
        self::assertSame('endfield', $game->gameBiz);
        self::assertSame('ake', $game->slug);
        self::assertSame('Arknights: Endfield', $game->name);
        self::assertSame(self::ICON, $game->iconUrl);
        self::assertSame(['endfield', 'arknights-endfield'], $game->aliases);
        self::assertTrue($game->matches('Arknights-Endfield'));
        self::assertSame(['endfield'], array_keys($provider->getGames()));
    }

    public function testDescribeFallsBackToASlugFromTheNameForAGameMissingFromTheMap(): void
    {
        $provider = $this->createProvider(games: [
            'endfield' => $this->createGameService('Arknights: Endfield', self::ICON),
            'future_game' => $this->createGameService('Future Game: Part II', null),
        ]);

        $game = $provider->describe(self::createProfile(8, gameBiz: 'future_game'));

        self::assertNotNull($game);
        self::assertSame('future_game', $game->gameBiz);
        self::assertSame('future-game-part-ii', $game->slug);
        self::assertSame([], $game->aliases);
        self::assertNull($game->iconUrl);
        self::assertSame(['endfield', 'future_game'], array_keys($provider->getGames()));
    }

    public function testDescribeReturnsNullForAGameWithoutService(): void
    {
        self::assertNull($this->createProvider()->describe(self::createProfile(7, gameBiz: 'unknown')));
    }

    public function testCreateContext(): void
    {
        $endfield = $this->createGameService('Arknights: Endfield', self::ICON);
        $provider = $this->createProvider(games: ['endfield' => $endfield]);
        $profile = self::createProfile(7);
        self::createAccount(1, self::createUser(1), [$profile]);

        $context = $provider->createContext($profile, fresh: true);

        self::assertSame($profile, $context->profile);
        self::assertSame('gryphline:7', (string) $context->ref);
        self::assertSame('ake', $context->game->slug);
        self::assertSame($endfield, $context->gameService);
        self::assertTrue($context->fresh);
        self::assertInstanceOf(RuntimeAccountData::class, $context->accountData);
        self::assertSame($profile, $context->accountData->getGameProfile());
        // Credentials are resolved lazily by the game service
        self::assertNull($context->accountData->getCredentials());
    }

    public function testCreateContextIsNotFreshByDefault(): void
    {
        self::assertFalse($this->createProvider()->createContext(self::createProfile(7))->fresh);
    }

    public function testCreateContextThrowsForAnUnknownGame(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No gryphline game service for game "unknown".');

        $this->createProvider()->createContext(self::createProfile(7, gameBiz: 'unknown'));
    }

    public function testCreateContextThrowsForAProfileWithoutId(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->createProvider()->createContext(self::createProfile(null));
    }

    public function testCreateContextThrowsForAProfileOfAnotherProvider(): void
    {
        $profile = $this->createStub(GameProfileInterface::class);
        $profile->method('getId')->willReturn(7);
        $profile->method('getGameBiz')->willReturn('endfield');

        $this->expectException(\RuntimeException::class);

        $this->createProvider()->createContext($profile);
    }

    public function testAccountHealthReportsAFailedSessionRefresh(): void
    {
        $account = self::createAccount(1, self::createUser(1))->setTokenRefreshFailed(true);

        $health = $this->createProvider()->getAccountHealth($account);

        self::assertFalse($health->ok);
        self::assertSame('The SKPort session could not be refreshed: log in again on the site.', $health->problem);
    }

    public function testAccountHealthIgnoresAnExpiredSession(): void
    {
        // The game service renews an expired session from the account token on the next call
        $account = self::createAccount(1, self::createUser(1))
            ->setSessionCred('cred')
            ->setSessionSalt('salt')
            ->setSessionExpiresAt(new \DateTimeImmutable('-1 day'));
        self::assertFalse($account->isSessionValid());

        $health = $this->createProvider()->getAccountHealth($account);

        self::assertTrue($health->ok);
        self::assertNull($health->problem);
    }

    public function testAccountHealthOfANewAccount(): void
    {
        self::assertTrue($this->createProvider()->getAccountHealth(self::createAccount(1, self::createUser(1)))->ok);
    }

    public function testAccountHealthOfAnotherProviderAccountIsOk(): void
    {
        self::assertTrue($this->createProvider()->getAccountHealth($this->createStub(GameAccountInterface::class))->ok);
    }
}
