<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\CoreBundle\Model\Game\PullItemIconQuery;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\PullIcon\NanokaIconResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PullIconResolversTest extends TestCase
{
    public function testNanokaIconResolverZzzHsrAndGenshin(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            return match ($url) {
                'https://static.nanoka.cc/manifest.json' => new MockResponse(json_encode([
                    'zzz' => ['live' => '3.3'],
                    'gi' => ['live' => '7.2'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/character.json' => new MockResponse(json_encode([
                    '1011' => ['en' => 'Anby', 'icon' => 'IconRole01'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/weapon.json' => new MockResponse(json_encode([
                    '12001' => ['en' => '[Lunar] Pleniluna', 'icon' => 'Weapon_B_Common_01'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/bangboo.json' => new MockResponse(json_encode([
                    '53001' => ['en' => 'Penguinboo', 'icon' => 'UI/Sprite/BangbooGarageRole12.png'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/gi/7.2/character.json' => new MockResponse(json_encode([
                    '10000002' => ['en' => 'Kamisato Ayaka', 'icon' => 'UI_AvatarIcon_Ayaka'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/gi/7.2/weapon.json' => new MockResponse(json_encode([
                    '11509' => ['en' => 'Mistsplitter Reforged', 'icon' => 'UI_EquipIcon_Sword_Narukami'],
                ], JSON_THROW_ON_ERROR)),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }, 'https://static.nanoka.cc');

        $catalog = new NanokaCatalogService($client, new ArrayAdapter());
        $resolver = new NanokaIconResolver($catalog);

        $this->assertSame(
            'https://static.nanoka.cc/assets/zzz/IconRoleCircle01.webp',
            $resolver->resolveUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '1011', itemType: 'Agents'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/zzz/Weapon_B_Common_01.webp',
            $resolver->resolveUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '12001', itemType: 'W-Engine'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/zzz/BangbooGarageRole12.webp',
            $resolver->resolveUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '53001', itemType: 'Bangboo'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/avatarroundicon/1001.webp',
            $resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '1001', itemType: 'Character'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/lightconemediumicon/21001.webp',
            $resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '21001', itemType: 'Light Cone'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_AvatarIcon_Ayaka.webp',
            $resolver->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Kamisato Ayaka', itemType: 'Character'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_EquipIcon_Sword_Narukami.webp',
            $resolver->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Mistsplitter Reforged', itemType: 'Weapon'))
        );
    }
}
