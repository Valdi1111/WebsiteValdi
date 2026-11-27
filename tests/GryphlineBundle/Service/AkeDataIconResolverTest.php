<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\PullIcon\AkeDataIconResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AkeDataIconResolverTest extends TestCase
{
    private const string SPRITES = 'assets/beyond/dynamicassets/gameplay/ui/sprites';
    private const string BASE_URL = 'https://data.akedata.wiki/public/images/' . self::SPRITES;

    public function testResolvesOperatorAndWeaponIncludingSwappedIconId(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return match ($url) {
                'https://data.akedata.wiki/manifest.json' => new MockResponse(json_encode([
                    'latest' => '1.6.4@11775769-12',
                    'versions' => [
                        ['id' => '1.6.4@11775769-12', 'tableCfgPath' => 'public/1.6.4/11775769-12/TableCfg'],
                    ],
                ], JSON_THROW_ON_ERROR)),
                'https://data.akedata.wiki/asset-sync-index.json' => new MockResponse(json_encode([
                    'datasets' => [
                        'images' => [
                            'root' => 'public/images',
                            'files' => [
                                'assets/beyond/dynamicassets/gameplay/ui/sprites/charremoteicon/icon_chr_0004_pelica.png' => ['size' => 100],
                                'assets/beyond/dynamicassets/gameplay/ui/sprites/itemicon/wpn_funnel_0010.png' => ['size' => 100],
                                'assets/beyond/dynamicassets/gameplay/ui/sprites/gachaweapon/wpn_sword_0005.png' => ['size' => 100],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)),
                'https://data.akedata.wiki/public/1.6.4/11775769-12/TableCfg/ItemTable.json' => new MockResponse(json_encode([
                    'chr_0004_pelica' => ['id' => 'chr_0004_pelica', 'iconId' => 'item_chr_0004_pelica'],
                    'wpn_funnel_0008' => ['id' => 'wpn_funnel_0008', 'iconId' => 'wpn_funnel_0010'],
                    'wpn_sword_0005' => ['id' => 'wpn_sword_0005', 'iconId' => 'wpn_sword_0005'],
                ], JSON_THROW_ON_ERROR)),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }, 'https://data.akedata.wiki');

        $resolver = new AkeDataIconResolver($client, new ArrayAdapter());

        $this->assertSame(
            'https://data.akedata.wiki/public/images/assets/beyond/dynamicassets/gameplay/ui/sprites/charremoteicon/icon_chr_0004_pelica.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'))
        );

        // Swapped iconId case: wpn_funnel_0008 -> iconId wpn_funnel_0010 in itemicon/
        $this->assertSame(
            'https://data.akedata.wiki/public/images/assets/beyond/dynamicassets/gameplay/ui/sprites/itemicon/wpn_funnel_0010.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_funnel_0008', itemType: 'weapon'))
        );

        // Fallback to gachaweapon/ when itemicon/ doesn't exist
        $this->assertSame(
            'https://data.akedata.wiki/public/images/assets/beyond/dynamicassets/gameplay/ui/sprites/gachaweapon/wpn_sword_0005.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_sword_0005', itemType: 'weapon'))
        );
    }

    public function testResolvesOperatorPortraitAsPreview(): void
    {
        $resolver = $this->createArtworkResolver();

        $this->assertInstanceOf(PullItemPreviewResolverInterface::class, $resolver);

        $this->assertSame(
            self::BASE_URL . '/characterportrait/chr_0004_pelica.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'))
        );
        // The chr_ prefix is enough without an item type
        $this->assertSame(
            self::BASE_URL . '/characterportrait/chr_0004_pelica.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica'))
        );
    }

    public function testResolvesWeaponGachaArtAsPreviewThroughIconId(): void
    {
        $resolver = $this->createArtworkResolver();

        // Swapped icon ids both ways: each weapon gets the art of its icon id, not the file named after it
        $this->assertSame(
            self::BASE_URL . '/gachaweapon/wpn_funnel_0010.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_funnel_0008', itemType: 'weapon'))
        );
        $this->assertSame(
            self::BASE_URL . '/gachaweapon/wpn_funnel_0008.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_funnel_0010', itemType: 'weapon'))
        );

        // A weapon without itemicon/ uses its gacha art both as icon and as preview
        $this->assertSame(
            self::BASE_URL . '/gachaweapon/wpn_sword_0005.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_sword_0005', itemType: 'weapon'))
        );

        // Direct fallback: a weapon missing from ItemTable resolves its art by id
        $this->assertSame(
            self::BASE_URL . '/gachaweapon/wpn_claym_0008.png',
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_claym_0008', itemType: 'weapon'))
        );
    }

    public function testReturnsNullPreviewWhenTheItemHasNoArtwork(): void
    {
        $resolver = $this->createArtworkResolver();

        // Operator with an icon (and an ItemTable itemicon) but no portrait: no artwork, not the icon
        $this->assertNull(
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0102_fiona', itemType: 'char'))
        );
        // Weapon with an itemicon/ but no gachaweapon/
        $this->assertNull(
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_pistol_0014', itemType: 'weapon'))
        );
        // Listed weapon whose icon id has no art: the file named after its own id is not used
        $this->assertNull(
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_wand_0002', itemType: 'weapon'))
        );
        // A file under arts/ui/sprites/gachaweapon/ is a UI decoration, not an artwork
        $this->assertNull(
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_lance_0001', itemType: 'weapon'))
        );
        // Unknown item, and another game
        $this->assertNull(
            $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_unknown_0001', itemType: 'weapon'))
        );
        $this->assertNull(
            $resolver->resolvePreviewUrl(\stdClass::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'))
        );
    }

    public function testIconsAreUnchangedByTheArtworks(): void
    {
        $client = $this->createArtworkClient();
        $resolver = new AkeDataIconResolver($client, new ArrayAdapter());

        $this->assertSame(
            self::BASE_URL . '/charremoteicon/icon_chr_0004_pelica.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'))
        );
        $this->assertSame(
            self::BASE_URL . '/charremoteicon/icon_chr_0102_fiona.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0102_fiona', itemType: 'char'))
        );
        // itemicon/ still wins over gachaweapon/ for the icon
        $this->assertSame(
            self::BASE_URL . '/itemicon/wpn_funnel_0010.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_funnel_0008', itemType: 'weapon'))
        );
        $this->assertSame(
            self::BASE_URL . '/gachaweapon/wpn_sword_0005.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_sword_0005', itemType: 'weapon'))
        );
        $this->assertSame(
            self::BASE_URL . '/itemicon/wpn_claym_0008.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_claym_0008', itemType: 'weapon'))
        );
        $this->assertSame(
            self::BASE_URL . '/itemicon/wpn_pistol_0014.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_pistol_0014', itemType: 'weapon'))
        );
        $this->assertSame(
            self::BASE_URL . '/itemicon/wpn_lance_0001.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_lance_0001', itemType: 'weapon'))
        );
        // The icon keeps its direct fallback for a listed weapon whose icon id has no file
        $this->assertSame(
            self::BASE_URL . '/itemicon/wpn_wand_0002.png',
            $resolver->resolveUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'wpn_wand_0002', itemType: 'weapon'))
        );

        // Icons and previews share one catalog build: manifest, asset index and ItemTable
        $resolver->resolvePreviewUrl(ArknightsEndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'));
        $this->assertSame(3, $client->getRequestsCount());
    }

    private function createArtworkResolver(): AkeDataIconResolver
    {
        return new AkeDataIconResolver($this->createArtworkClient(), new ArrayAdapter());
    }

    private function createArtworkClient(): MockHttpClient
    {
        $files = [];
        foreach ([
            'charremoteicon/icon_chr_0004_pelica.png',
            'characterportrait/chr_0004_pelica.png',
            'charremoteicon/icon_chr_0102_fiona.png',
            'itemicon/item_chr_0102_fiona.png',
            'itemicon/wpn_funnel_0008.png',
            'itemicon/wpn_funnel_0010.png',
            'gachaweapon/wpn_funnel_0008.png',
            'gachaweapon/wpn_funnel_0010.png',
            'gachaweapon/wpn_sword_0005.png',
            'itemicon/wpn_claym_0008.png',
            'gachaweapon/wpn_claym_0008.png',
            'itemicon/wpn_pistol_0014.png',
            'itemicon/wpn_wand_0002.png',
            'gachaweapon/wpn_wand_0002.png',
            'itemicon/wpn_lance_0001.png',
        ] as $sprite) {
            $files[self::SPRITES . '/' . $sprite] = ['size' => 100];
        }
        $files['assets/beyond/arts/ui/sprites/gachaweapon/wpn_lance_0001.png'] = ['size' => 100];

        return new MockHttpClient(function (string $method, string $url) use ($files): MockResponse {
            return match ($url) {
                'https://data.akedata.wiki/manifest.json' => new MockResponse(json_encode([
                    'latest' => '1.7.2@12190603-5',
                    'versions' => [
                        ['id' => '1.7.2@12190603-5', 'tableCfgPath' => 'public/1.7.2/12190603-5/TableCfg'],
                    ],
                ], JSON_THROW_ON_ERROR)),
                'https://data.akedata.wiki/asset-sync-index.json' => new MockResponse(json_encode([
                    'datasets' => [
                        'images' => [
                            'root' => 'public/images',
                            'files' => $files,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)),
                'https://data.akedata.wiki/public/1.7.2/12190603-5/TableCfg/ItemTable.json' => new MockResponse(json_encode([
                    'chr_0004_pelica' => ['id' => 'chr_0004_pelica', 'iconId' => 'item_chr_0004_pelica'],
                    'chr_0102_fiona' => ['id' => 'chr_0102_fiona', 'iconId' => 'item_chr_0102_fiona'],
                    'wpn_funnel_0008' => ['id' => 'wpn_funnel_0008', 'iconId' => 'wpn_funnel_0010'],
                    'wpn_funnel_0010' => ['id' => 'wpn_funnel_0010', 'iconId' => 'wpn_funnel_0008'],
                    'wpn_sword_0005' => ['id' => 'wpn_sword_0005', 'iconId' => 'wpn_sword_0005'],
                    'wpn_pistol_0014' => ['id' => 'wpn_pistol_0014', 'iconId' => 'wpn_pistol_0014'],
                    // Hypothetical: listed with an icon id that has neither icon nor art
                    'wpn_wand_0002' => ['id' => 'wpn_wand_0002', 'iconId' => 'wpn_wand_0099'],
                    'wpn_lance_0001' => ['id' => 'wpn_lance_0001', 'iconId' => 'wpn_lance_0001'],
                ], JSON_THROW_ON_ERROR)),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }, 'https://data.akedata.wiki');
    }
}
