<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Service\PullItemIconBinaryLoader;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PullItemIconComponentsTest extends TestCase
{
    public function testPathCodecEncodeAndDecodeForIdAndName(): void
    {
        $codec = new PullItemIconPathCodec();

        $idPath = $codec->encode('hkrpg_global', new PullItemIconQuery(itemId: '1001', itemType: 'Character'));
        $this->assertSame('hkrpg_global/id/character/1001.webp', $idPath);

        $decodedId = $codec->decode($idPath);
        $this->assertNotNull($decodedId);
        $this->assertSame('hkrpg_global', $decodedId['gameBiz']);
        $this->assertSame('1001', $decodedId['query']->itemId);
        $this->assertSame('character', $decodedId['query']->itemType);

        $namePath = $codec->encode('hk4e_global', new PullItemIconQuery(itemId: '', name: '"The Catch"', itemType: 'Weapon'));
        $this->assertNotNull($namePath);
        $this->assertStringStartsWith('hk4e_global/name/weapon/', $namePath);
        $this->assertStringEndsWith('.webp', $namePath);

        $decodedName = $codec->decode($namePath);
        $this->assertNotNull($decodedName);
        $this->assertSame('hk4e_global', $decodedName['gameBiz']);
        $this->assertSame('"The Catch"', $decodedName['query']->name);
        $this->assertSame('weapon', $decodedName['query']->itemType);

        $this->assertNull($codec->decode('../../etc/passwd'));
        $this->assertNull($codec->decode('hk4e_global/url/https://evil.com/a.png'));
    }

    public function testResolverManagerOrdersByPriorityAndSurvivesExceptions(): void
    {
        $failingPrimary = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [GenshinImpactService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                throw new \RuntimeException('Primary down');
            }
        };

        $workingFallback = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [GenshinImpactService::class => 50];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://example.com/' . $query->name . '.png';
            }
        };

        $manager = new PullItemIconResolverManager([$workingFallback, $failingPrimary]);

        $this->assertTrue($manager->supportsGameService(GenshinImpactService::class));
        $this->assertTrue($manager->supportsGameBiz('hk4e_global'));
        $this->assertFalse($manager->supportsGameService(HonkaiStarRailService::class));

        $urls = $manager->resolveCandidateUrlsByGameBiz('hk4e_global', new PullItemIconQuery(name: 'Ayaka'));
        $this->assertSame(['https://example.com/Ayaka.png'], $urls);
        $this->assertSame('https://example.com/Ayaka.png', $manager->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Ayaka')));
    }

    public function testBinaryLoaderCascadesAcrossProviders(): void
    {
        $first = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn1.example/missing.png';
            }
        };

        $second = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 50];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn2.example/1001.webp';
            }
        };

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if ($url === 'https://cdn1.example/missing.png') {
                return new MockResponse('', ['http_code' => 404]);
            }
            return new MockResponse('WEBPBYTES', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/webp'],
            ]);
        });

        $loader = new PullItemIconBinaryLoader(
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([$second, $first]),
            $httpClient,
        );

        $binary = $loader->find('hkrpg_global/id/character/1001.webp');
        $this->assertSame('WEBPBYTES', $binary->getContent());
        $this->assertSame('image/webp', $binary->getMimeType());
        $this->assertSame('webp', $binary->getFormat());
    }

    public function testBinaryLoaderThrowsNotLoadableOnInvalidPath(): void
    {
        $loader = new PullItemIconBinaryLoader(
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([]),
            new MockHttpClient(),
        );

        $this->expectException(NotLoadableException::class);
        $loader->find('invalid/path');
    }

    public function testUrlBuilderEnrichesRows(): void
    {
        $resolver = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://example.com/1001.png';
            }
        };

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->once())
            ->method('getBrowserPath')
            ->with('hkrpg_global/id/character/1001.webp', PullItemIconUrlBuilder::FILTER_NAME)
            ->willReturn('https://localhost/media/cache/games_pull_item_icon/hkrpg_global/id/character/1001.webp');

        $builder = new PullItemIconUrlBuilder(
            $cacheManager,
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([$resolver]),
        );

        $enriched = $builder->enrichPullRows(HonkaiStarRailService::class, [
            ['item_id' => '1001', 'name' => 'March 7th', 'item_type' => 'Character', 'rarity' => 4],
        ]);

        $this->assertSame(
            'https://localhost/media/cache/games_pull_item_icon/hkrpg_global/id/character/1001.webp',
            $enriched[0]['icon_url']
        );
    }
}
