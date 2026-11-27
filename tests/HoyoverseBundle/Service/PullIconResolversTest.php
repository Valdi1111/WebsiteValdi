<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
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
            'https://static.nanoka.cc/assets/hsr/avatarroundicon/1006.webp',
            $resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '1006'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/lightconemediumicon/20000.webp',
            $resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '20000'))
        );
        $this->assertNull($resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: 'abc', itemType: 'Character')));
        $this->assertNull($resolver->resolveUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '12345')));
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_AvatarIcon_Ayaka.webp',
            $resolver->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Kamisato Ayaka', itemType: 'Character'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_EquipIcon_Sword_Narukami.webp',
            $resolver->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Mistsplitter Reforged', itemType: 'Weapon'))
        );
    }

    public function testNanokaIconResolverPreviewUrls(): void
    {
        $resolver = new NanokaIconResolver(new NanokaCatalogService($this->createNanokaPreviewClient(), new ArrayAdapter()));

        // Genshin: wish splash art derived from the icon key, characters and the five standard weapon classes.
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_Gacha_AvatarImg_Shougun.webp',
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Raiden Shogun', itemType: 'Character'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_Gacha_AvatarImg_Collei.webp',
            // A service instance (here a subclass) resolves like the FQCN.
            $resolver->resolvePreviewUrl($this->createStub(GenshinImpactService::class), new PullItemIconQuery(itemId: '10000067'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_Gacha_EquipIcon_Pole_Narukami.webp',
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Engulfing Lightning', itemType: 'Weapon'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/gi/UI_Gacha_EquipIcon_Sword_Blunt.webp',
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: '11101', itemType: 'Weapon'))
        );
        $this->assertNull(
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: '224001', itemType: 'Weapon')),
            'Weapon classes outside the standard five have no splash art'
        );
        $this->assertNull(
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: '310001', itemType: 'Weapon')),
            'Templated "_{0}" icon keys have no splash art'
        );
        $this->assertNull(
            $resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: '224008', itemType: 'Weapon')),
            'Icon keys without the UI_EquipIcon_ prefix have no splash art'
        );
        $this->assertNull($resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(name: 'Unknown Item')));

        // HSR: gacha card and light cone figure by numeric id, with the same type detection as the icons.
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/avatardrawcard/1102.webp',
            $resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '1102', itemType: 'Character'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/lightconemaxfigures/23001.webp',
            $resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '23001', itemType: 'Light Cone'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/avatardrawcard/1006.webp',
            $resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '1006'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/hsr/lightconemaxfigures/20000.webp',
            $resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '20000'))
        );
        $this->assertNull($resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: 'abc', itemType: 'Character')));
        $this->assertNull($resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(name: 'Acheron', itemType: 'Character')));
        $this->assertNull($resolver->resolvePreviewUrl(HonkaiStarRailService::class, new PullItemIconQuery(itemId: '12345')));

        // ZZZ: agent portrait "IconRole<n>" from the round icon; W-Engines and Bangboos have no larger art.
        $this->assertSame(
            'https://static.nanoka.cc/assets/zzz/IconRole01.webp',
            $resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '1011', itemType: 'Agents'))
        );
        $this->assertSame(
            'https://static.nanoka.cc/assets/zzz/IconRole21.webp',
            $resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(name: 'Yanagi'))
        );
        $this->assertNull($resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '14102', itemType: 'W-Engine')));
        $this->assertNull($resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(name: 'Steel Cushion')));
        $this->assertNull($resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '53001', itemType: 'Bangboo')));
        $this->assertNull($resolver->resolvePreviewUrl(ZenlessZoneZeroService::class, new PullItemIconQuery(itemId: '99999', itemType: 'Agents')));

        // Unsupported game.
        $this->assertNull($resolver->resolvePreviewUrl(\stdClass::class, new PullItemIconQuery(itemId: '1102', itemType: 'Character')));
    }

    private function createNanokaPreviewClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $json = match ($url) {
                'https://static.nanoka.cc/manifest.json' => [
                    'zzz' => ['live' => '3.3'],
                    'gi' => ['live' => '7.2'],
                ],
                'https://static.nanoka.cc/zzz/3.3/character.json' => [
                    '1011' => ['en' => 'Anby', 'icon' => 'IconRole01'],
                    '1221' => ['en' => 'Yanagi', 'icon' => 'IconRole21'],
                ],
                'https://static.nanoka.cc/zzz/3.3/weapon.json' => [
                    '14102' => ['en' => 'Steel Cushion', 'icon' => 'Weapon_S_1021'],
                ],
                'https://static.nanoka.cc/zzz/3.3/bangboo.json' => [
                    '53001' => ['en' => 'Penguinboo', 'icon' => 'UI/Sprite/BangbooGarageRole12.png'],
                ],
                'https://static.nanoka.cc/gi/7.2/character.json' => [
                    '10000052' => ['en' => 'Raiden Shogun', 'icon' => 'UI_AvatarIcon_Shougun', 'rank' => 'QUALITY_ORANGE'],
                    '10000067' => ['en' => 'Collei', 'icon' => 'UI_AvatarIcon_Collei', 'rank' => 'QUALITY_PURPLE'],
                ],
                'https://static.nanoka.cc/gi/7.2/weapon.json' => [
                    '11101' => ['en' => 'Dull Blade', 'icon' => 'UI_EquipIcon_Sword_Blunt', 'rank' => 1],
                    '13509' => ['en' => 'Engulfing Lightning', 'icon' => 'UI_EquipIcon_Pole_Narukami', 'rank' => 5],
                    '224001' => ['en' => "Shatanaya's Frostsilver", 'icon' => 'UI_EquipIcon_AssaultRifle_Glacia', 'rank' => 4],
                    '224008' => ['en' => 'Specialized Frag Crystals within the Shadow Realm', 'icon' => 'Sword_Template', 'rank' => 4],
                    '310001' => ['en' => 'Serpent Devourer', 'icon' => 'UI_EquipIcon_Sword_MorphVinces_{0}', 'rank' => 4],
                ],
                default => null,
            };

            return $json !== null
                ? new MockResponse(json_encode($json, JSON_THROW_ON_ERROR))
                : new MockResponse('{}', ['http_code' => 404]);
        }, 'https://static.nanoka.cc');
    }
}
