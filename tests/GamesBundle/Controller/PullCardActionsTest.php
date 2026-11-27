<?php

namespace App\Tests\GamesBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Exception\PullCardUnavailableException;
use App\GamesBundle\Exception\TooManyPullCardsException;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardQuery;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Service\Pull\Card\PullCardIconInliner;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;
use App\GryphlineBundle\Controller\ApiGameProfilesController as GryphlineGameProfilesController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullCardBuilder;
use App\HoyoverseBundle\Controller\ApiGameProfilesController as HoyoverseGameProfilesController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullCardBuilder;
use App\PerfectworldBundle\Controller\ApiGameProfilesController as PerfectworldGameProfilesController;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullCardBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The shareable pulls card action of the three game providers: ownership, rate limit, banner errors and the
 * node-services rendering, with the real renderer (Twig template included) and the real limiter.
 */
class PullCardActionsTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\nfake card bytes";

    /**
     * @return iterable<string, array{string}>
     */
    public static function providers(): iterable
    {
        yield 'HoYoverse' => ['hoyoverse'];
        yield 'Gryphline' => ['gryphline'];
        yield 'Perfect World' => ['perfectworld'];
    }

    private static function createUser(int $id = 1): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * Controller, account and profiles of a provider: the profile belongs to the account, the foreign one to another
     * account of the same user.
     *
     * @return array{controller: object, account: object, profile: object, foreignProfile: object, builderClass: class-string, filename: string}
     */
    private function createFixture(string $provider, User $user): array
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $projectDir = sys_get_temp_dir();

        switch ($provider) {
            case 'hoyoverse':
                $account = (new HoyoverseAccount())->setUser($user);
                $foreignAccount = (new HoyoverseAccount())->setUser($user);

                return [
                    'controller' => new HoyoverseGameProfilesController($entityManager, new ServiceLocator([]), $projectDir),
                    'account' => $account,
                    'profile' => (new HoyoverseGameProfile())->setAccount($account)->setGameId(GenshinImpactService::GAME_ID),
                    'foreignProfile' => (new HoyoverseGameProfile())->setAccount($foreignAccount)->setGameId(GenshinImpactService::GAME_ID),
                    'builderClass' => HoyoversePullCardBuilder::class,
                    'filename' => 'genshin-impact-all-banners.png',
                ];
            case 'gryphline':
                $account = (new GryphlineAccount())->setUser($user);
                $foreignAccount = (new GryphlineAccount())->setUser($user);

                return [
                    'controller' => new GryphlineGameProfilesController($entityManager, new ServiceLocator([]), $projectDir),
                    'account' => $account,
                    'profile' => (new GryphlineGameProfile())->setAccount($account)->setGameBiz(ArknightsEndfieldService::GAME_BIZ),
                    'foreignProfile' => (new GryphlineGameProfile())->setAccount($foreignAccount)->setGameBiz(ArknightsEndfieldService::GAME_BIZ),
                    'builderClass' => GryphlinePullCardBuilder::class,
                    'filename' => 'arknights-endfield-all-banners.png',
                ];
            case 'perfectworld':
                $account = (new PerfectworldAccount())->setUser($user);
                $foreignAccount = (new PerfectworldAccount())->setUser($user);
                $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID);
                $account->addGameProfile($profile);
                $foreignProfile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID);
                $foreignAccount->addGameProfile($foreignProfile);

                return [
                    'controller' => new PerfectworldGameProfilesController($entityManager, new ServiceLocator([]), $projectDir),
                    'account' => $account,
                    'profile' => $profile,
                    'foreignProfile' => $foreignProfile,
                    'builderClass' => PerfectworldPullCardBuilder::class,
                    'filename' => 'neverness-to-everness-all-banners.png',
                ];
            default:
                throw new \LogicException(sprintf('Unknown provider "%s".', $provider));
        }
    }

    private static function createCard(string $gameName): PullCard
    {
        return new PullCard(
            gameName: $gameName,
            title: 'All banners',
            sLabel: 'S-Rank',
            pullLabel: 'Pulls',
            sections: [
                new PullCardSection(
                    name: 'Main banner',
                    totalPulls: 1234,
                    sCount: 5,
                    avgSPity: 61.5,
                    sPity: 12,
                    sMaxPity: 90,
                    items: [
                        new PullCardItem('Featured One', 74, isUp: true, iconPath: 'icons/featured_one.webp'),
                        new PullCardItem('Standard Two', 49, isUp: false, isManual: true, hasDeclaredPity: true),
                    ],
                    hiddenCount: 3,
                    upCount: 1,
                    upKnown: 2,
                    pullLabel: 'Rolls',
                ),
                new PullCardSection(name: 'Weapon banner', totalPulls: 0, sCount: 0, avgSPity: 0.0, sPity: 0, sMaxPity: 80, items: []),
            ],
            generatedAt: new \DateTimeImmutable('2026-11-27 08:30:00'),
            playerName: 'Player One',
            playerUid: '123456789',
        );
    }

    /**
     * The real renderer: the Twig template of the card, the fonts of the bundle, no icon (each one shows a placeholder).
     *
     * @param list<string> $inlinedIcons filled with the icon paths the renderer asked for
     */
    private static function createRenderer(HttpClientInterface $nodeServices, array &$inlinedIcons = []): PullCardRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 3).'/src/GamesBundle/templates', 'Games');
        $twig = new Environment($loader, ['strict_variables' => true]);

        $iconInliner = new class($inlinedIcons) extends PullCardIconInliner {
            /**
             * @param list<string> $inlinedIcons
             */
            public function __construct(private array &$inlinedIcons)
            {
            }

            public function dataUri(string $iconPath): ?string
            {
                $this->inlinedIcons[] = $iconPath;

                return null;
            }
        };

        return new PullCardRenderer($twig, $iconInliner, $nodeServices, new AsciiSlugger());
    }

    /**
     * Same settings as games_pull_card, each test with its own storage.
     */
    private static function createLimiter(int $limit = 20): PullCardLimiter
    {
        return new PullCardLimiter(new RateLimiterFactory(
            ['id' => 'games_pull_card', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '10 minutes'],
            new InMemoryStorage(),
        ));
    }

    /**
     * node-services that must not be reached.
     */
    private static function createUnreachableNodeServices(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new \LogicException('node-services must not be called.');
        });
    }

    /**
     * @param array{controller: object, account: object, profile: object} $fixture
     */
    private static function callAction(
        array $fixture,
        User $user,
        object $profile,
        MockObject $builder,
        PullCardRenderer $renderer,
        PullCardLimiter $limiter,
        PullCardQuery $query = new PullCardQuery(),
    ): Response {
        // Same signature in the three controllers
        return $fixture['controller']->apiGameProfilePullsCard($user, $fixture['account'], $profile, $builder, $renderer, $limiter, $query);
    }

    #[DataProvider('providers')]
    public function testRendersThePngOfTheCardBuiltWithTheQueryOptions(string $provider): void
    {
        $user = self::createUser();
        $fixture = $this->createFixture($provider, $user);
        $card = self::createCard(match ($provider) {
            'hoyoverse' => 'Genshin Impact',
            'gryphline' => 'Arknights: Endfield',
            default => 'Neverness to Everness',
        });

        $builder = $this->createMock($fixture['builderClass']);
        $builder->expects($this->once())
            ->method('build')
            ->with($fixture['profile'], 'banner_key', true, false)
            ->willReturn($card);

        $requests = [];
        $nodeServices = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'payload' => json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR)];

            return new MockResponse(self::PNG, ['http_code' => 200, 'response_headers' => ['Content-Type' => 'image/png']]);
        }, 'http://node-services.test');
        $inlinedIcons = [];

        $response = self::callAction(
            $fixture,
            $user,
            $fixture['profile'],
            $builder,
            self::createRenderer($nodeServices, $inlinedIcons),
            self::createLimiter(),
            new PullCardQuery(banner: 'banner_key', all: true, player: false),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(self::PNG, $response->getContent());
        $this->assertSame('attachment; filename='.$fixture['filename'], $response->headers->get('Content-Disposition'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('http://node-services.test/render/png', $requests[0]['url']);
        $payload = $requests[0]['payload'];
        $this->assertSame(PullCardRenderer::WIDTH, $payload['width']);
        $this->assertSame('#card', $payload['selector']);
        $this->assertSame(['icons/featured_one.webp'], $inlinedIcons);

        // The card made it into the HTML screenshotted by node-services
        $html = $payload['html'];
        $this->assertStringContainsString('<div class="title">All banners</div>', $html);
        $this->assertStringContainsString('Player One · UID 123456789', $html);
        $this->assertStringContainsString('Total Rolls', $html, 'The unit of the section');
        $this->assertStringContainsString('1,234', $html);
        $this->assertStringContainsString('<span>+3</span>', $html);
        $this->assertStringContainsString('<div class="section-name">Weapon banner</div>', $html);
        $this->assertStringContainsString('No S-Rank pulls yet', $html, 'The unit of the game on a section without its own');
        $this->assertStringContainsString('27 Nov 2026', $html);
        $this->assertStringContainsString('data:font/woff2;base64,', $html, 'The font is embedded');
    }

    #[DataProvider('providers')]
    public function testRefusesAProfileOfAnotherAccountBeforeCountingIt(string $provider): void
    {
        $user = self::createUser();
        $fixture = $this->createFixture($provider, $user);
        $builder = $this->createMock($fixture['builderClass']);
        $builder->expects($this->never())->method('build');
        $limiter = self::createLimiter(1);

        try {
            self::callAction($fixture, $user, $fixture['foreignProfile'], $builder, self::createRenderer(self::createUnreachableNodeServices()), $limiter);
            $this->fail('A profile of another account must be refused.');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Game profile does not belong to this account.', $e->getMessage());
        }

        // The only card of the quota is still there
        $limiter->consume($user);
    }

    #[DataProvider('providers')]
    public function testTurnsAnInvalidBannerIntoABadRequest(string $provider): void
    {
        $user = self::createUser();
        $fixture = $this->createFixture($provider, $user);
        $invalid = new \InvalidArgumentException('Unknown banner "nope".');
        $builder = $this->createMock($fixture['builderClass']);
        $builder->expects($this->once())
            ->method('build')
            ->with($fixture['profile'], 'nope', false, false)
            ->willThrowException($invalid);

        try {
            self::callAction(
                $fixture,
                $user,
                $fixture['profile'],
                $builder,
                self::createRenderer(self::createUnreachableNodeServices()),
                self::createLimiter(),
                new PullCardQuery(banner: 'nope'),
            );
            $this->fail('An invalid banner must be refused.');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Unknown banner "nope".', $e->getMessage());
            $this->assertSame($invalid, $e->getPrevious());
        }
    }

    #[DataProvider('providers')]
    public function testStopsBeforeBuildingWhenTheLimiterIsExhausted(string $provider): void
    {
        $user = self::createUser();
        $fixture = $this->createFixture($provider, $user);
        $builder = $this->createMock($fixture['builderClass']);
        $builder->expects($this->never())->method('build');
        $limiter = self::createLimiter(1);
        // The only card of the quota, asked elsewhere (e.g. a chat command)
        $limiter->consume($user);

        try {
            self::callAction($fixture, $user, $fixture['profile'], $builder, self::createRenderer(self::createUnreachableNodeServices()), $limiter);
            $this->fail('The limiter must refuse the card.');
        } catch (TooManyPullCardsException $e) {
            $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $e->getStatusCode());
            $this->assertGreaterThan(new \DateTimeImmutable(), $e->retryAfter);
            $this->assertGreaterThanOrEqual(1, (int) $e->getHeaders()['Retry-After']);
        }
    }

    #[DataProvider('providers')]
    public function testReportsANodeServicesErrorAsUnavailable(string $provider): void
    {
        $user = self::createUser();
        $fixture = $this->createFixture($provider, $user);
        $builder = $this->createMock($fixture['builderClass']);
        // Every banner, newest S only, with the player shown
        $builder->expects($this->once())
            ->method('build')
            ->with($fixture['profile'], null, false, true)
            ->willReturn(self::createCard('Some Game'));
        $nodeServices = new MockHttpClient(new MockResponse('Chromium crashed', ['http_code' => 500]), 'http://node-services.test');

        try {
            self::callAction(
                $fixture,
                $user,
                $fixture['profile'],
                $builder,
                self::createRenderer($nodeServices),
                self::createLimiter(),
                new PullCardQuery(player: true),
            );
            $this->fail('A failed rendering must be reported.');
        } catch (PullCardUnavailableException $e) {
            $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $e->getStatusCode());
            $this->assertSame(PullCardUnavailableException::MESSAGE, $e->getMessage());
        }
    }
}
