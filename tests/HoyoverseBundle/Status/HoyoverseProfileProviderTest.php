<?php

namespace App\Tests\HoyoverseBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\TearsOfThemisService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use App\HoyoverseBundle\Status\HoyoverseProfileProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * HoYoverse adapter of the cross-provider profile layer, without database: repositories and cookie parsing are
 * doubles, the game services are the real ones.
 */
class HoyoverseProfileProviderTest extends TestCase
{
    private const string COOKIE = 'ltoken_v2=v2_test; ltuid_v2=100000001; ltmid_v2=mid_test';

    private static function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private static function account(int $id, User $user, bool $cookieRefreshFailed = false): HoyoverseAccount
    {
        $account = new HoyoverseAccount()
            ->setUser($user)
            ->setCookie(self::COOKIE)
            ->setCookieRefreshFailed($cookieRefreshFailed);
        new \ReflectionProperty(HoyoverseAccount::class, 'id')->setValue($account, $id);

        return $account;
    }

    private static function profile(int $id, HoyoverseAccount $account, string $gameBiz = GenshinImpactService::GAME_BIZ, bool $active = true): HoyoverseGameProfile
    {
        $profile = new HoyoverseGameProfile()
            ->setGameBiz($gameBiz)
            ->setGameName('Genshin Impact')
            ->setActive($active);
        new \ReflectionProperty(HoyoverseGameProfile::class, 'id')->setValue($profile, $id);
        $account->addGameProfile($profile);

        return $profile;
    }

    /**
     * Game services in the order of the container's hoyoverse.game.biz locator.
     */
    private static function gameServices(): ServiceLocator
    {
        $logger = new NullLogger();
        $services = [
            GenshinImpactService::GAME_BIZ => new GenshinImpactService($logger),
            HonkaiImpact3rdService::GAME_BIZ => new HonkaiImpact3rdService($logger),
            HonkaiStarRailService::GAME_BIZ => new HonkaiStarRailService($logger),
            TearsOfThemisService::GAME_BIZ => new TearsOfThemisService($logger),
            ZenlessZoneZeroService::GAME_BIZ => new ZenlessZoneZeroService($logger),
        ];

        return new ServiceLocator(array_map(static fn (GameInterface $service): \Closure => static fn (): GameInterface => $service, $services));
    }

    /**
     * @param list<HoyoverseGameProfile> $profiles found by id
     */
    private function provider(
        array $profiles = [],
        ?HoyoverseAccountRepository $accountRepository = null,
        ?HoyolabCookieUtilsService $cookieUtils = null,
        ?ServiceLocator $gameServices = null,
    ): HoyoverseProfileProvider {
        $byId = [];
        foreach ($profiles as $profile) {
            $byId[$profile->getId()] = $profile;
        }
        $profileRepository = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepository->method('find')->willReturnCallback(static fn (mixed $id): ?HoyoverseGameProfile => $byId[$id] ?? null);

        return new HoyoverseProfileProvider(
            $accountRepository ?? $this->createStub(HoyoverseAccountRepository::class),
            $profileRepository,
            $cookieUtils ?? $this->createStub(HoyolabCookieUtilsService::class),
            $gameServices ?? self::gameServices(),
        );
    }

    public function testKeyAndProfileClass(): void
    {
        $provider = $this->provider();

        $this->assertSame('hoyoverse', $provider->getKey());
        $this->assertSame(HoyoverseGameProfile::class, $provider->getProfileClass());
    }

    public function testProfilesOfAUserAreTheActiveOnesByAccountThenProfileId(): void
    {
        $user = self::user(7);
        // Accounts and profiles out of order, with an inactive profile
        $second = self::account(20, $user);
        self::profile(9, $second);
        self::profile(3, $second, active: false);
        self::profile(5, $second, HonkaiStarRailService::GAME_BIZ);
        $first = self::account(10, $user);
        self::profile(12, $first);
        self::profile(4, $first, ZenlessZoneZeroService::GAME_BIZ);

        $accountRepository = $this->createMock(HoyoverseAccountRepository::class);
        $accountRepository->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user], ['id' => 'ASC'])
            ->willReturn([$second, $first]);

        $profiles = $this->provider(accountRepository: $accountRepository)->findProfilesOf($user);

        $this->assertSame([4, 12, 5, 9], array_map(static fn (HoyoverseGameProfile $profile): ?int => $profile->getId(), $profiles));
    }

    public function testUserWithoutAccountsHasNoProfiles(): void
    {
        $accountRepository = $this->createStub(HoyoverseAccountRepository::class);
        $accountRepository->method('findBy')->willReturn([]);

        $this->assertSame([], $this->provider(accountRepository: $accountRepository)->findProfilesOf(self::user(7)));
    }

    public function testProfileOfAUserIsFoundByTheIdOfItsOwner(): void
    {
        $profile = self::profile(42, self::account(10, self::user(7)), active: false);
        $provider = $this->provider([$profile]);

        // Another instance of the same user, as for a chat request; inactive profiles are found too
        $this->assertSame($profile, $provider->findProfileOf(self::user(7), 42));
        $this->assertNull($provider->findProfileOf(self::user(8), 42));
        $this->assertNull($provider->findProfileOf(self::user(7), 43));
    }

    public function testProfileOfAUserWithoutIdIsNotFound(): void
    {
        $profile = self::profile(42, self::account(10, new User()));

        $this->assertNull($this->provider([$profile])->findProfileOf(new User(), 42));
    }

    public function testGamesHaveTheSlugsAndAliasesOfTheMap(): void
    {
        $games = $this->provider()->getGames();

        $this->assertSame(['hk4e_global', 'bh3_global', 'hkrpg_global', 'nxx_global', 'nap_global'], array_keys($games));
        $this->assertSame(
            [
                'hk4e_global' => ['gi', 'Genshin Impact', ['genshin', 'genshin-impact']],
                'bh3_global' => ['hi3', 'Honkai Impact 3rd', ['bh3', 'honkai-impact-3rd']],
                'hkrpg_global' => ['hsr', 'Honkai: Star Rail', ['starrail', 'star-rail', 'honkai-star-rail']],
                'nxx_global' => ['tot', 'Tears of Themis', ['tears-of-themis']],
                'nap_global' => ['zzz', 'Zenless Zone Zero', ['zenless', 'zenless-zone-zero']],
            ],
            array_map(static fn (GameDescriptor $game): array => [$game->slug, $game->name, $game->aliases], $games),
        );
        foreach ($games as $gameBiz => $game) {
            $this->assertSame('hoyoverse', $game->provider);
            $this->assertSame($gameBiz, $game->gameBiz);
            $this->assertNull($game->iconUrl);
        }
    }

    public function testDescribeFindsTheGameOfAProfile(): void
    {
        $provider = $this->provider();
        $account = self::account(10, self::user(7));

        $game = $provider->describe(self::profile(42, $account, HonkaiStarRailService::GAME_BIZ));
        $this->assertSame('hsr', $game?->slug);
        $this->assertTrue($game->matches('Star-Rail'));

        $this->assertNull($provider->describe(self::profile(43, $account, 'unknown_global')));
    }

    public function testGameMissingFromTheMapGetsASlugFromItsName(): void
    {
        $service = $this->createStub(GameInterface::class);
        $service->method('getGameName')->willReturn('Some New Game: Remastered');

        $games = $this->provider(gameServices: new ServiceLocator(['new_global' => static fn (): GameInterface => $service]))->getGames();

        $this->assertSame(['new_global'], array_keys($games));
        $this->assertSame('some-new-game-remastered', $games['new_global']->slug);
        $this->assertSame('Some New Game: Remastered', $games['new_global']->name);
        $this->assertSame([], $games['new_global']->aliases);
    }

    public function testContextHoldsTheGameServiceAndTheParsedCookie(): void
    {
        $profile = self::profile(42, self::account(10, self::user(7)), HonkaiStarRailService::GAME_BIZ);
        $parsedCookie = new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test');
        $cookieUtils = $this->createMock(HoyolabCookieUtilsService::class);
        $cookieUtils->expects($this->once())->method('tryParseCookie')->with(self::COOKIE)->willReturn($parsedCookie);
        $gameServices = self::gameServices();

        $context = $this->provider([$profile], cookieUtils: $cookieUtils, gameServices: $gameServices)->createContext($profile, fresh: true);

        $this->assertSame($profile, $context->profile);
        $this->assertSame('hoyoverse:42', (string) $context->ref);
        $this->assertSame('hsr', $context->game->slug);
        $this->assertSame($gameServices->get(HonkaiStarRailService::GAME_BIZ), $context->gameService);
        $this->assertInstanceOf(RuntimeAccountData::class, $context->accountData);
        $this->assertSame($parsedCookie, $context->accountData->getParsedCookie());
        $this->assertSame($profile, $context->accountData->getGameProfile());
        $this->assertTrue($context->fresh);
    }

    public function testContextOfAGameWithoutServiceFails(): void
    {
        $profile = self::profile(42, self::account(10, self::user(7)), 'unknown_global');
        $cookieUtils = $this->createMock(HoyolabCookieUtilsService::class);
        $cookieUtils->expects($this->never())->method('tryParseCookie');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hoyoverse game service for game "unknown_global".');

        $this->provider([$profile], cookieUtils: $cookieUtils)->createContext($profile);
    }

    public function testContextOfAnAccountWithAnInvalidCookieFailsWithoutShowingIt(): void
    {
        $profile = self::profile(42, self::account(10, self::user(7)));
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('tryParseCookie')->willReturn(null);

        try {
            $this->provider([$profile], cookieUtils: $cookieUtils)->createContext($profile);
            $this->fail('The context of an unreadable account must not be built.');
        } catch (ConfigurationException $e) {
            $this->assertSame('The HoYoLAB cookie of account #10 is missing or invalid.', $e->getMessage());
            $this->assertStringNotContainsString('v2_test', $e->getMessage());
        }
    }

    public function testAccountHealthReportsAFailedCookieRefresh(): void
    {
        $provider = $this->provider();
        $user = self::user(7);

        $this->assertTrue($provider->getAccountHealth(self::account(10, $user))->ok);
        $this->assertNull($provider->getAccountHealth(self::account(10, $user))->problem);

        $health = $provider->getAccountHealth(self::account(11, $user, cookieRefreshFailed: true));
        $this->assertFalse($health->ok);
        $this->assertSame('The HoYoLAB session could not be refreshed: log in again on the site.', $health->problem);

        $this->assertTrue($provider->getAccountHealth($this->createStub(GameAccountInterface::class))->ok);
    }
}
