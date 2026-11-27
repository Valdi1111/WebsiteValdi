<?php

namespace App\Tests\GamesBundle\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
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

    /**
     * Missing-pulls endpoints: same paths for every provider, told apart by HTTP method.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function manualPullRoutesProvider(): iterable
    {
        foreach (['hoyoverse', 'gryphline', 'perfectworld'] as $provider) {
            $base = sprintf('/api/%s/accounts/1/gameProfiles/2/pulls', $provider);
            $name = sprintf('%1$s_api_%1$s_game_profiles_', $provider);

            yield $provider . ' suggestions' => ['GET', $base . '/manual/suggestions', $name . 'pulls_manual_suggestions'];
            yield $provider . ' create manual S' => ['POST', $base . '/manual', $name . 'pulls_manual_create'];
            yield $provider . ' declare pity' => ['PATCH', $base . '/3/declared-pity', $name . 'pulls_declared_pity'];
            yield $provider . ' delete manual S' => ['DELETE', $base . '/3', $name . 'pulls_manual_delete'];
            yield $provider . ' list keeps GET' => ['GET', $base, $name . 'pulls_list'];
            // Shareable card: a static segment, never taken for a pull id
            yield $provider . ' shareable card' => ['GET', $base . '/card', $name . 'pulls_card'];
        }
    }

    #[DataProvider('manualPullRoutesProvider')]
    public function testManualPullRoutes(string $method, string $path, string $expectedRoute): void
    {
        $this->router->getContext()->setMethod($method);

        $this->assertSame($expectedRoute, $this->router->match($path)['_route']);
    }

    public function testManualPullDeleteRequiresANumericPullId(): void
    {
        $this->router->getContext()->setMethod('DELETE');

        // "/pulls/manual" exists only for POST: DELETE never reaches the delete action (405)
        $this->expectException(MethodNotAllowedException::class);
        $this->router->match('/api/hoyoverse/accounts/1/gameProfiles/2/pulls/manual');
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
