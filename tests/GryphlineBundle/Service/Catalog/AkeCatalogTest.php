<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AkeCatalogTest extends TestCase
{
    private const array CATALOG = [
        'version' => AkeDataFixture::LATEST,
        'built_at' => '2026-11-23T06:00:00+01:00',
        'items' => [
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'],
            'chr_0009_azrila' => ['id' => 'chr_0009_azrila', 'name' => 'Ember', 'rarity' => 6, 'type' => 'char'],
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
        ],
        'pools' => [
            'joint_1_2_2' => ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => 3, 'up' => ['chr_0016_laevat', 'chr_0013_aglina']],
            'rerun_wpn_aglina' => ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => 1, 'up' => ['wpn_funnel_0011']],
            'special_1_0_3' => ['id' => 'special_1_0_3', 'category' => 'char', 'type' => 0, 'up' => ['chr_0013_aglina']],
            'standard' => ['id' => 'standard', 'category' => 'char', 'type' => 2, 'up' => []],
        ],
    ];

    public function testFindReturnsTheItemShape(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertTrue($catalog->isLoaded());
        $this->assertSame(AkeDataFixture::LATEST, $catalog->getVersion());
        $this->assertSame('2026-11-23T06:00:00+01:00', $catalog->getBuiltAt());

        $this->assertSame(['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'], $catalog->find('chr_0005_chen'));
        $this->assertSame(['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'], $catalog->find(' wpn_funnel_0011 '));
        $this->assertNull($catalog->find('wpn_unknown_0001'));
        $this->assertNull($catalog->find(''));
    }

    public function testFindPoolReturnsThePoolShape(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertSame(
            ['id' => 'special_1_0_3', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_SPECIAL, 'up' => ['chr_0013_aglina']],
            $catalog->findPool('special_1_0_3'),
        );
        $this->assertSame(
            ['id' => 'joint_1_2_2', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_JOINT, 'up' => ['chr_0016_laevat', 'chr_0013_aglina']],
            $catalog->findPool('joint_1_2_2'),
        );
        $this->assertSame(
            ['id' => 'standard', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_STANDARD, 'up' => []],
            $catalog->findPool('standard'),
        );
        $this->assertSame(
            ['id' => 'rerun_wpn_aglina', 'category' => 'weapon', 'type' => AkeCatalog::WEAPON_POOL_TYPE_RERUN, 'up' => ['wpn_funnel_0011']],
            $catalog->findPool(' rerun_wpn_aglina '),
        );

        $this->assertNull($catalog->findPool('special_9_9_9'));
        $this->assertNull($catalog->findPool(''));
    }

    public function testFindPoolRejectsMalformedEntries(): void
    {
        $catalog = $this->createCatalog(['pools' => [
            'no_type' => ['id' => 'no_type', 'category' => 'char', 'up' => []],
            'string_type' => ['id' => 'string_type', 'category' => 'char', 'type' => '0', 'up' => []],
            'unknown_category' => ['id' => 'unknown_category', 'category' => 'item', 'type' => 0, 'up' => []],
            'dirty_up' => ['id' => 'other_id', 'category' => 'weapon', 'type' => 0, 'up' => ['wpn_funnel_0011', 42, '', null]],
            'no_up' => ['id' => 'no_up', 'category' => 'char', 'type' => 2],
        ]] + self::CATALOG);

        $this->assertNull($catalog->findPool('no_type'));
        $this->assertNull($catalog->findPool('string_type'));
        $this->assertNull($catalog->findPool('unknown_category'));
        $this->assertSame(['id' => 'dirty_up', 'category' => 'weapon', 'type' => 0, 'up' => ['wpn_funnel_0011']], $catalog->findPool('dirty_up'));
        $this->assertSame(['id' => 'no_up', 'category' => 'char', 'type' => 2, 'up' => []], $catalog->findPool('no_up'));
    }

    public function testCatalogStoredBeforeThePoolsHasNoPool(): void
    {
        $stored = self::CATALOG;
        unset($stored['pools']);

        // Written raw, as an older build left it: save() would add an empty pool list
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem(AkeCatalog::CACHE_KEY)->set($stored));
        $catalog = new AkeCatalog($pool);

        $this->assertTrue($catalog->isLoaded());
        $this->assertSame('Ember', $catalog->find('chr_0009_azrila')['name']);
        $this->assertNull($catalog->findPool('special_1_0_3'));
        $this->assertSame([], $catalog->all()['pools']);
    }

    public function testAllReturnsTheWholeCatalog(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertSame(self::CATALOG, $catalog->all());
    }

    public function testEmptyPoolMeansNotLoaded(): void
    {
        $catalog = new AkeCatalog(new ArrayAdapter());

        $this->assertFalse($catalog->isLoaded());
        $this->assertNull($catalog->getVersion());
        $this->assertNull($catalog->getBuiltAt());
        $this->assertNull($catalog->find('chr_0005_chen'));
        $this->assertNull($catalog->findPool('special_1_0_3'));
        $this->assertSame(['version' => null, 'built_at' => null, 'items' => [], 'pools' => []], $catalog->all());
    }

    public function testSaveRefreshesTheMemoAndResetRereadsThePool(): void
    {
        $pool = new ArrayAdapter();
        $reader = new AkeCatalog($pool);
        $this->assertFalse($reader->isLoaded());

        // Another process (the weekly task) saves a catalog into the shared pool
        $writer = new AkeCatalog($pool);
        $writer->save(self::CATALOG);
        $this->assertSame('Ember', $writer->find('chr_0009_azrila')['name']);
        $this->assertNull($reader->findPool('special_1_0_3'));

        // The reader keeps its memo until the kernel resets it between worker messages
        $this->assertFalse($reader->isLoaded());
        $reader->reset();
        $this->assertTrue($reader->isLoaded());
        $this->assertSame('Ember', $reader->find('chr_0009_azrila')['name']);
        $this->assertSame(['chr_0013_aglina'], $reader->findPool('special_1_0_3')['up']);
    }

    public function testTypeFromItemIdMatchesThePoolCategories(): void
    {
        $this->assertSame('char', AkeCatalog::typeFromItemId('chr_0005_chen'));
        $this->assertSame('weapon', AkeCatalog::typeFromItemId('wpn_funnel_0011'));
        $this->assertNull(AkeCatalog::typeFromItemId('item_gold'));
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function createCatalog(array $stored): AkeCatalog
    {
        $catalog = new AkeCatalog(new ArrayAdapter());
        $catalog->save($stored);
        $catalog->reset();

        return $catalog;
    }
}
