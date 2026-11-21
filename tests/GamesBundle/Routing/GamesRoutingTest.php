<?php

namespace App\Tests\GamesBundle\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Exercises the real router on the shared games host: provider APIs live under /api/{provider},
 * everything else (except /api) falls back to the games SPA.
 */
class GamesRoutingTest extends KernelTestCase
{
    private RouterInterface $router;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->router = $container->get('router');
        $this->router->getContext()
            ->setHost((string) $container->getParameter('games.domain_name'))
            ->setScheme('https')
            ->setMethod('GET');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gamesHostRoutesProvider(): iterable
    {
        yield 'hoyoverse accounts api' => ['/api/hoyoverse/accounts', 'hoyoverse_api_hoyoverse_accounts_get'];
        yield 'gryphline accounts api' => ['/api/gryphline/accounts', 'gryphline_api_gryphline_accounts_get'];
        yield 'hoyoverse pulls overview api' => ['/api/hoyoverse/accounts/1/gameProfiles/2/pulls/overview', 'hoyoverse_api_hoyoverse_game_profiles_pulls_overview'];
        yield 'gryphline pulls overview api' => ['/api/gryphline/accounts/1/gameProfiles/2/pulls/overview', 'gryphline_api_gryphline_game_profiles_pulls_overview'];
        yield 'perfectworld accounts api' => ['/api/perfectworld/accounts', 'perfectworld_api_perfectworld_accounts_get'];
        yield 'perfectworld game profile api' => ['/api/perfectworld/accounts/1/gameProfiles/2', 'perfectworld_api_perfectworld_game_profiles_id_get'];
        yield 'perfectworld pulls overview api' => ['/api/perfectworld/accounts/1/gameProfiles/2/pulls/overview', 'perfectworld_api_perfectworld_game_profiles_pulls_overview'];
        yield 'perfectworld pull operation api' => ['/api/perfectworld/accounts/1/gameProfiles/2/pulls/operations/abc', 'perfectworld_api_perfectworld_game_profiles_pulls_operation_status'];
        yield 'public powershell script' => ['/api/hoyoverse/pulls/script/powershell', 'hoyoverse_public_pulls_script_powershell'];
        // Built by BackendApi.getGeetestFrameUrl() in the SPA
        yield 'hoyoverse geetest frame' => ['/api/hoyoverse/accounts/login/geetest-frame', 'hoyoverse_api_hoyoverse_accounts_auth_geetest_frame'];
        yield 'gryphline geetest frame' => ['/api/gryphline/accounts/login/geetest-frame', 'gryphline_api_gryphline_accounts_auth_geetest_frame'];
        yield 'shared geetest script' => ['/api/geetest-script/v4', 'games_geetest_script'];
        yield 'spa root' => ['/', 'games_index'];
        yield 'spa hoyoverse tab' => ['/hoyoverse', 'games_index'];
        yield 'spa gryphline pulls deep link' => ['/gryphline/accounts/1/profiles/2/pulls', 'games_index'];
        yield 'spa perfectworld tab' => ['/perfectworld', 'games_index'];
    }

    #[DataProvider('gamesHostRoutesProvider')]
    public function testGamesHostRoutes(string $path, string $expectedRoute): void
    {
        $match = $this->router->match($path);

        $this->assertSame($expectedRoute, $match['_route']);
    }

    public function testLegacyUnprefixedApiPathIsNotRouted(): void
    {
        // Before GamesBundle each provider exposed /api/accounts on its own sub-domain
        $this->expectException(ResourceNotFoundException::class);
        $this->router->match('/api/accounts');
    }

    public function testProviderRoutesAreBoundToTheGamesHostOnly(): void
    {
        $this->router->getContext()->setHost('hoyoverse.' . static::getContainer()->getParameter('domain_name'));

        $this->expectException(ResourceNotFoundException::class);
        $this->router->match('/api/hoyoverse/accounts');
    }
}
