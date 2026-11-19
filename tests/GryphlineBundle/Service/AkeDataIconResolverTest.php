<?php

namespace App\Tests\GryphlineBundle\Service;

use App\CoreBundle\Model\Game\PullItemIconQuery;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\PullIcon\AkeDataIconResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AkeDataIconResolverTest extends TestCase
{
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
            $resolver->resolveUrl(EndfieldService::class, new PullItemIconQuery(itemId: 'chr_0004_pelica', itemType: 'char'))
        );

        // Swapped iconId case: wpn_funnel_0008 -> iconId wpn_funnel_0010 in itemicon/
        $this->assertSame(
            'https://data.akedata.wiki/public/images/assets/beyond/dynamicassets/gameplay/ui/sprites/itemicon/wpn_funnel_0010.png',
            $resolver->resolveUrl(EndfieldService::class, new PullItemIconQuery(itemId: 'wpn_funnel_0008', itemType: 'weapon'))
        );

        // Fallback to gachaweapon/ when itemicon/ doesn't exist
        $this->assertSame(
            'https://data.akedata.wiki/public/images/assets/beyond/dynamicassets/gameplay/ui/sprites/gachaweapon/wpn_sword_0005.png',
            $resolver->resolveUrl(EndfieldService::class, new PullItemIconQuery(itemId: 'wpn_sword_0005', itemType: 'weapon'))
        );
    }
}
