<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\PullIcon\NteAssetsIconResolver;
use PHPUnit\Framework\TestCase;
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
                'fork_twinbirds' => ['id' => 'fork_twinbirds', 'name' => 'Crime and Punishment', 'rank' => 'S', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_twinbirds_256.png'],
                'fashion_glide_1075' => ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
            ],
            'avatars' => [],
            'pools' => [],
        ]);

        return $catalog;
    }
}
