<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogBuilder;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class EndfieldCatalogUpdaterTest extends TestCase
{
    private const array PREVIOUS_CATALOG = [
        'version' => AkeDataFixture::OLDER,
        'built_at' => '2026-11-16T06:00:00+01:00',
        'items' => [
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'],
            'wpn_old_0001' => ['id' => 'wpn_old_0001', 'name' => 'Old Weapon', 'rarity' => 6, 'type' => 'weapon'],
        ],
    ];

    public function testBuildsAndSavesTheCatalog(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new EndfieldCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        $result = $this->createUpdater(new AkeDataFixture(), $catalog)->update();

        $this->assertSame(AkeDataFixture::LATEST, $result['catalog']['version']);
        $this->assertSame(5, $result['new_items']);

        // Saved in the pool: a fresh reader sees it, and the full tables replace the previous catalog
        $reader = new EndfieldCatalog($pool);
        $this->assertSame(AkeDataFixture::LATEST, $reader->getVersion());
        $this->assertSame('Delivery Guaranteed', $reader->find('wpn_funnel_0011')['name']);
        $this->assertNull($reader->find('wpn_old_0001'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function failedBuildProvider(): iterable
    {
        yield 'source unavailable' => [['manifest.json' => null]];
        yield 'empty catalog' => [[AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json' => []]];
    }

    /**
     * @param array<string, mixed> $files
     */
    #[DataProvider('failedBuildProvider')]
    public function testFailedBuildKeepsThePreviousCatalog(array $files): void
    {
        $pool = new ArrayAdapter();
        $catalog = new EndfieldCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        try {
            $this->createUpdater(new AkeDataFixture($files), $catalog)->update();
            $this->fail('A failed build must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('previous catalog kept', $e->getMessage());
        }

        $this->assertSame(self::PREVIOUS_CATALOG, $catalog->all());
        $this->assertSame(self::PREVIOUS_CATALOG, new EndfieldCatalog($pool)->all());
    }

    private function createUpdater(AkeDataFixture $fixture, EndfieldCatalog $catalog): EndfieldCatalogUpdater
    {
        return new EndfieldCatalogUpdater(new EndfieldCatalogBuilder($fixture->client(), new NullLogger()), $catalog, new NullLogger());
    }
}
