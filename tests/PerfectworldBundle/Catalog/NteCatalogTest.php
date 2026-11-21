<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NteCatalogTest extends TestCase
{
    private const string SHA = '0123456789abcdef0123456789abcdef01234567';

    private const array CATALOG = [
        'sha' => self::SHA,
        'version' => '1.5.4',
        'built_at' => '2026-11-23T06:00:00+01:00',
        'items' => [
            '1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png'],
            'fork_vine' => ['id' => 'fork_vine', 'name' => 'Be Happy', 'rank' => 'B', 'type' => 'arc', 'icon_path' => 'UI_Icon/Fork/fork_HugVine_256.png'],
            'fashion_glide_1075' => ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
            'fashion_1042_1' => ['id' => 'Fashion_1042_1', 'name' => 'Wandering Stars', 'rank' => 'A', 'type' => 'cosmetic', 'icon_path' => 'UI_Icon/AvatarImage/256/player_heiyu1_256.png'],
            'green_item' => ['id' => 'Green_item', 'name' => 'Green Thing', 'rank' => null, 'type' => 'item', 'icon_path' => null],
            'spaced' => ['id' => 'Spaced', 'name' => 'Spaced', 'rank' => 'B', 'type' => 'item', 'icon_path' => 'UI_Icon/Item/with space.png'],
        ],
        'avatars' => ['1042' => 'UI_Icon/AvatarImage/256/player_heiyu_256.png'],
        'pools' => ['lottery_heiyu' => 'Foretold Finale', 'forklottery_heiyu' => 'Foretold Special'],
    ];

    public function testLookupsAreCaseInsensitive(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertTrue($catalog->isLoaded());
        $this->assertSame(self::SHA, $catalog->getSha());
        $this->assertSame('1.5.4', $catalog->getVersion());

        $this->assertSame(
            ['id' => 'Fashion_glide_1075', 'name' => 'Sheepcopter', 'rank' => 'A', 'rarity' => 4, 'type' => 'cosmetic', 'icon_path' => 'UI/Appearance/Glider/Fashion_Glide_1075.png'],
            $catalog->find('Fashion_Glide_1075'),
        );
        $this->assertSame(5, $catalog->find('1042')['rarity']);
        $this->assertSame(3, $catalog->find('FORK_VINE')['rarity']);
        $this->assertNull($catalog->find('green_item')['rarity']);
        $this->assertNull($catalog->find('unknown_id'));
    }

    public function testIconUrlsPointToTheCatalogCommit(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);
        $base = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';

        $this->assertSame($base . 'UI_Icon/Fork/fork_HugVine_256.png', $catalog->getIconUrl('fork_vine'));
        $this->assertSame($base . 'UI/Appearance/Glider/Fashion_Glide_1075.png', $catalog->getIconUrl('fashion_glide_1075'));
        $this->assertSame($base . 'UI_Icon/Item/with%20space.png', $catalog->getIconUrl('spaced'));
        $this->assertNull($catalog->getIconUrl('green_item'));
        $this->assertNull($catalog->getIconUrl('unknown_id'));
    }

    public function testAvatarAndPoolLookups(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);
        $base = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';

        $this->assertSame($base . 'UI_Icon/AvatarImage/256/player_heiyu_256.png', $catalog->findAvatarIconUrl('1042'));
        // Not in the avatar table: falls back to the item with the same id
        $this->assertSame($base . 'UI_Icon/AvatarImage/256/player_heiyu1_256.png', $catalog->findAvatarIconUrl('Fashion_1042_1'));
        $this->assertNull($catalog->findAvatarIconUrl('Fashion_9999_1'));
        $this->assertNull($catalog->findAvatarIconUrl(null));
        $this->assertNull($catalog->findAvatarIconUrl(''));

        $this->assertSame('Foretold Finale', $catalog->findPoolName('Lottery_HeiYu'));
        $this->assertSame('Foretold Special', $catalog->findPoolName('ForkLottery_HeiYu'));
        $this->assertNull($catalog->findPoolName('Lottery_Unknown'));
        $this->assertNull($catalog->findPoolName(null));
    }

    public function testEmptyPoolMeansNotLoaded(): void
    {
        $catalog = new NteCatalog(new ArrayAdapter());

        $this->assertFalse($catalog->isLoaded());
        $this->assertNull($catalog->getSha());
        $this->assertNull($catalog->getVersion());
        $this->assertNull($catalog->find('1042'));
        $this->assertNull($catalog->getIconUrl('1042'));
        $this->assertNull($catalog->findAvatarIconUrl('1042'));
        $this->assertNull($catalog->findPoolName('Lottery_HeiYu'));
        $this->assertSame([], $catalog->all()['items']);
    }

    public function testSaveRefreshesTheMemoAndResetRereadsThePool(): void
    {
        $pool = new ArrayAdapter();
        $reader = new NteCatalog($pool);
        $this->assertFalse($reader->isLoaded());

        // Another process (the weekly command) saves a catalog into the shared pool
        $writer = new NteCatalog($pool);
        $writer->save(self::CATALOG);
        $this->assertSame('Blackbird', $writer->find('1042')['name']);

        // The reader keeps its memo until the kernel resets it between worker messages
        $this->assertFalse($reader->isLoaded());
        $reader->reset();
        $this->assertTrue($reader->isLoaded());
        $this->assertSame('Blackbird', $reader->find('1042')['name']);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function createCatalog(array $stored): NteCatalog
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $catalog->save($stored);
        $catalog->reset();

        return $catalog;
    }
}
