<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\PullIcon\NteAssetsIconResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NteAssetsIconResolverTest extends TestCase
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    public function testResolvesIconUrlFromTheCatalog(): void
    {
        $resolver = new NteAssetsIconResolver($this->createCatalog());

        $this->assertSame([NevernessToEvernessService::class => 100], NteAssetsIconResolver::getSupportedGameServices());
        $this->assertSame(
            'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/UI_Icon/Fork/fork_twinbirds_256.png',
            $resolver->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'fork_twinbirds', itemType: 'arc')),
        );
        $this->assertSame(
            'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/UI/Appearance/Glider/Fashion_Glide_1075.png',
            $resolver->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'Fashion_Glide_1075')),
        );

        $this->assertNull($resolver->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'unknown_id')));
        $this->assertNull($resolver->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(name: 'Crime and Punishment')));
        $this->assertNull($resolver->resolveUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: 'fork_twinbirds')));
        $this->assertNull(new NteAssetsIconResolver(new NteCatalog(new ArrayAdapter()))->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'fork_twinbirds')));
    }

    public function testResolvesPreviewUrlFromTheCatalog(): void
    {
        $resolver = new NteAssetsIconResolver($this->createCatalog());
        $base = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';

        $this->assertInstanceOf(PullItemPreviewResolverInterface::class, $resolver);

        // Gacha splash and outfit art of the characters, 1024px Arc
        $this->assertSame($base . 'UI/Gacha/YH_lihui_character_nanali.png', $resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: '1010', itemType: 'character')));
        $this->assertSame($base . 'UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.png', $resolver->resolvePreviewUrl(new NevernessToEvernessService(new NullLogger()), new PullItemIconQuery(itemId: '1042')));
        $this->assertSame($base . 'UI_Icon/Fork/1024/fork_twinbirds.png', $resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: ' FORK_TWINBIRDS ', itemType: 'arc')));

        // No artwork (or a catalog built before the previews): null, so the preview falls back to the icon
        $this->assertNull($resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'Fashion_Glide_1075')));
        $this->assertNotNull($resolver->resolveUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'Fashion_Glide_1075')));
        $this->assertNull($resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'dicenormal')));

        $this->assertNull($resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: 'unknown_id')));
        $this->assertNull($resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: ' ')));
        $this->assertNull($resolver->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(name: 'Blackbird')));
        $this->assertNull($resolver->resolvePreviewUrl(GenshinImpactService::class, new PullItemIconQuery(itemId: '1042')));
        $this->assertNull(new NteAssetsIconResolver(new NteCatalog(new ArrayAdapter()))->resolvePreviewUrl(NevernessToEvernessService::class, new PullItemIconQuery(itemId: '1042')));
    }

    public function testSharedPipelineResolvesTheCachedPathWithoutTheCommit(): void
    {
        $manager = new PullItemIconResolverManager([new NteAssetsIconResolver($this->createCatalog())]);
        $codec = new PullItemIconPathCodec();

        // The cached image path depends on game, type and id only: a new catalog commit keeps it valid
        $path = $codec->encode(NevernessToEvernessService::getGameBiz(), new PullItemIconQuery(itemId: 'fork_twinbirds', itemType: 'arc'));
        $this->assertSame('nte/id/arc/fork_twinbirds.webp', $path);

        $decoded = $codec->decode($path);
        $this->assertSame(
            ['https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/UI_Icon/Fork/fork_twinbirds_256.png'],
            $manager->resolveCandidateUrlsByGameBiz($decoded['gameBiz'], $decoded['query']),
        );
    }

    private function createCatalog(): NteCatalog
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $catalog->save([
            'sha' => self::SHA,
            'version' => '1.5.4',
            'built_at' => '2026-11-23T06:00:00+01:00',
            'items' => [
                'fork_twinbirds' => ['id' => 'fork_twinbirds', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_twinbirds_256.png', 'preview_path' => 'UI_Icon/Fork/1024/fork_twinbirds.png'],
                // Built before the previews: no preview_path
                'fashion_glide_1075' => ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
                '1010' => ['id' => '1010', 'name' => 'Nanally', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/player_010.png', 'preview_path' => 'UI/Gacha/YH_lihui_character_nanali.png'],
                '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png', 'preview_path' => 'UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.png'],
                'dicenormal' => ['id' => 'DiceNormal', 'name' => 'Fabricated Dice', 'rank' => 'S', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/DiceNormal.png', 'preview_path' => null],
            ],
            'avatars' => [],
            'pools' => [],
        ]);

        return $catalog;
    }
}
