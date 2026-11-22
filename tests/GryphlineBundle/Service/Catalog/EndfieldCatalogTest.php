<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class EndfieldCatalogTest extends TestCase
{
    private const array CATALOG = [
        'version' => AkeDataFixture::LATEST,
        'built_at' => '2026-11-23T06:00:00+01:00',
        'items' => [
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'],
            'chr_0009_azrila' => ['id' => 'chr_0009_azrila', 'name' => 'Ember', 'rarity' => 6, 'type' => 'char'],
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
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

    public function testAllReturnsTheWholeCatalog(): void
    {
        $catalog = $this->createCatalog(self::CATALOG);

        $this->assertSame(self::CATALOG, $catalog->all());
    }

    public function testEmptyPoolMeansNotLoaded(): void
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());

        $this->assertFalse($catalog->isLoaded());
        $this->assertNull($catalog->getVersion());
        $this->assertNull($catalog->getBuiltAt());
        $this->assertNull($catalog->find('chr_0005_chen'));
        $this->assertSame(['version' => null, 'built_at' => null, 'items' => []], $catalog->all());
    }

    public function testSaveRefreshesTheMemoAndResetRereadsThePool(): void
    {
        $pool = new ArrayAdapter();
        $reader = new EndfieldCatalog($pool);
        $this->assertFalse($reader->isLoaded());

        // Another process (the weekly task) saves a catalog into the shared pool
        $writer = new EndfieldCatalog($pool);
        $writer->save(self::CATALOG);
        $this->assertSame('Ember', $writer->find('chr_0009_azrila')['name']);

        // The reader keeps its memo until the kernel resets it between worker messages
        $this->assertFalse($reader->isLoaded());
        $reader->reset();
        $this->assertTrue($reader->isLoaded());
        $this->assertSame('Ember', $reader->find('chr_0009_azrila')['name']);
    }

    public function testTypeFromItemIdMatchesThePoolCategories(): void
    {
        $this->assertSame('char', EndfieldCatalog::typeFromItemId('chr_0005_chen'));
        $this->assertSame('weapon', EndfieldCatalog::typeFromItemId('wpn_funnel_0011'));
        $this->assertNull(EndfieldCatalog::typeFromItemId('item_gold'));
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function createCatalog(array $stored): EndfieldCatalog
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());
        $catalog->save($stored);
        $catalog->reset();

        return $catalog;
    }
}
