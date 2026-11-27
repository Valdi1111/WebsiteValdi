<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\GamesBundle\Service\PullItemIconBinaryLoader;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GamesBundle\Service\PullItemPreviewBinaryLoader;
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

        $builder = new PullItemIconUrlBuilder(
            $this->createCacheManager(),
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
        $this->assertSame(
            'https://localhost/media/cache/games_pull_item_preview/hkrpg_global/id/character/1001.webp',
            $enriched[0]['preview_url']
        );
    }

    public function testUrlBuilderSkipsFillerRows(): void
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

        $builder = new PullItemIconUrlBuilder(
            $this->createCacheManager(),
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([$resolver]),
        );

        $enriched = $builder->enrichPullRows(HonkaiStarRailService::class, [
            ['item_id' => null, 'name' => 'Unknown', 'item_type' => 'Unknown', 'rarity' => 0, 'origin' => 'filler'],
            ['item_id' => '1001', 'name' => 'March 7th', 'item_type' => 'Character', 'rarity' => 4, 'origin' => 'recorded'],
        ]);

        $this->assertArrayHasKey('icon_url', $enriched[0]);
        $this->assertNull($enriched[0]['icon_url']);
        $this->assertArrayHasKey('preview_url', $enriched[0]);
        $this->assertNull($enriched[0]['preview_url']);
        $this->assertSame(
            'https://localhost/media/cache/games_pull_item_icon/hkrpg_global/id/character/1001.webp',
            $enriched[1]['icon_url']
        );
    }

    public function testUrlBuilderGivesNoImageToABonusEntryWithoutItem(): void
    {
        $builder = new PullItemIconUrlBuilder(
            $this->createCacheManager(),
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([$this->createIconResolver('https://example.com/icon.png')]),
        );

        $enriched = $builder->enrichPullRows(HonkaiStarRailService::class, [
            ['item_id' => null, 'name' => 'Headhunting Dossier', 'bonus_label' => 'Headhunting Dossier'],
            ['item_id' => '23001', 'name' => 'In the Night', 'item_type' => 'Light Cone', 'bonus_label' => 'Tribute'],
            ['item_id' => null, 'name' => 'March 7th', 'item_type' => 'Character'],
        ]);

        $this->assertNull($enriched[0]['icon_url'], 'Its icon would be looked up by name and never found');
        $this->assertNull($enriched[0]['preview_url']);
        $this->assertNotNull($enriched[1]['icon_url'], 'A bonus entry with an item keeps its images');
        $this->assertNotNull($enriched[1]['preview_url']);
        $this->assertNotNull($enriched[2]['icon_url'], 'A row without item id is still looked up by name');
    }

    public function testResolverManagerReturnsArtworkCandidatesOfPreviewResolversOnly(): void
    {
        $iconOnly = $this->createIconResolver('https://cdn.example/icon.png');
        $failing = new class implements PullItemPreviewResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return null;
            }

            public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                throw new \RuntimeException('catalog down');
            }
        };
        $artwork = $this->createPreviewResolver('https://cdn.example/icon.png', 'https://cdn.example/art.png');

        $manager = new PullItemIconResolverManager([$artwork, $iconOnly, $failing]);
        $query = new PullItemIconQuery(itemId: '1001');

        $this->assertSame(['https://cdn.example/art.png'], $manager->resolvePreviewCandidateUrlsByGameBiz('hkrpg_global', $query));
        $this->assertSame(['https://cdn.example/icon.png'], $manager->resolveCandidateUrlsByGameBiz('hkrpg_global', $query));
    }

    public function testPreviewLoaderReturnsTheArtwork(): void
    {
        $loader = $this->createPreviewLoader(
            $this->createPreviewResolver('https://cdn.example/icon.png', 'https://cdn.example/art.png'),
            ['https://cdn.example/art.png' => 200, 'https://cdn.example/icon.png' => 200],
        );

        $this->assertSame('https://cdn.example/art.png', $loader->find('hkrpg_global/id/character/1001.webp')->getContent());
    }

    public function testPreviewLoaderFallsBackToTheIconWhenTheArtworkDoesNotExist(): void
    {
        $loader = $this->createPreviewLoader(
            $this->createPreviewResolver('https://cdn.example/icon.png', 'https://cdn.example/art.png'),
            ['https://cdn.example/art.png' => 404, 'https://cdn.example/icon.png' => 200],
        );

        $this->assertSame('https://cdn.example/icon.png', $loader->find('hkrpg_global/id/character/1001.webp')->getContent());
    }

    public function testPreviewLoaderFallsBackToTheIconWithoutArtwork(): void
    {
        $iconOnly = $this->createPreviewLoader(
            $this->createIconResolver('https://cdn.example/icon.png'),
            ['https://cdn.example/icon.png' => 200],
        );
        $noArtwork = $this->createPreviewLoader(
            $this->createPreviewResolver('https://cdn.example/icon.png', null),
            ['https://cdn.example/icon.png' => 200],
        );

        $this->assertSame('https://cdn.example/icon.png', $iconOnly->find('hkrpg_global/id/character/1001.webp')->getContent());
        $this->assertSame('https://cdn.example/icon.png', $noArtwork->find('hkrpg_global/id/character/1001.webp')->getContent());
    }

    /**
     * LiipImagine would cache the icon for good in place of the artwork: a failure that may be temporary throws.
     */
    public function testPreviewLoaderDoesNotFallBackToTheIconOnAFailure(): void
    {
        foreach ([500, 'timeout'] as $failure) {
            $loader = $this->createPreviewLoader(
                $this->createPreviewResolver('https://cdn.example/icon.png', 'https://cdn.example/art.png'),
                ['https://cdn.example/art.png' => $failure, 'https://cdn.example/icon.png' => 200],
            );

            try {
                $loader->find('hkrpg_global/id/character/1001.webp');
                $this->fail(sprintf('A %s artwork must not fall back to the icon.', $failure));
            } catch (NotLoadableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPreviewLoaderThrowsWhenNothingCanBeLoaded(): void
    {
        $loader = $this->createPreviewLoader(
            $this->createPreviewResolver('https://cdn.example/icon.png', 'https://cdn.example/art.png'),
            ['https://cdn.example/art.png' => 404, 'https://cdn.example/icon.png' => 404],
        );

        $this->expectException(NotLoadableException::class);
        $loader->find('hkrpg_global/id/character/1001.webp');
    }

    /**
     * Cache manager returning the URL LiipImagine would give for the filter and the path.
     */
    private function createCacheManager(): CacheManager
    {
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willReturnCallback(
            static fn (string $path, string $filter): string => sprintf('https://localhost/media/cache/%s/%s', $filter, $path)
        );

        return $cacheManager;
    }

    private function createIconResolver(string $iconUrl): PullItemIconResolverInterface
    {
        return new class($iconUrl) implements PullItemIconResolverInterface {
            public function __construct(private readonly string $iconUrl)
            {
            }

            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return $this->iconUrl;
            }
        };
    }

    private function createPreviewResolver(string $iconUrl, ?string $artworkUrl): PullItemPreviewResolverInterface
    {
        return new class($iconUrl, $artworkUrl) implements PullItemPreviewResolverInterface {
            public function __construct(private readonly string $iconUrl, private readonly ?string $artworkUrl)
            {
            }

            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return $this->iconUrl;
            }

            public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return $this->artworkUrl;
            }
        };
    }

    /**
     * Preview loader over a CDN answering each URL with its status ('timeout': a transport error); a 200 answers
     * with the URL itself as content.
     *
     * @param array<string, int|string> $responses
     */
    private function createPreviewLoader(PullItemIconResolverInterface $resolver, array $responses): PullItemPreviewBinaryLoader
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($responses): MockResponse {
            $status = $responses[$url] ?? 404;
            if ($status === 'timeout') {
                return new MockResponse([new \RuntimeException('Idle timeout reached')]);
            }

            return new MockResponse($status === 200 ? $url : '', [
                'http_code' => $status,
                'response_headers' => ['content-type' => 'image/png'],
            ]);
        });

        return new PullItemPreviewBinaryLoader(new PullItemIconPathCodec(), new PullItemIconResolverManager([$resolver]), $httpClient);
    }
}
