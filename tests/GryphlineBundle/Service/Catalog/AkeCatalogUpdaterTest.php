<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Catalog\AkeCatalogUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AkeCatalogUpdaterTest extends TestCase
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
        $catalog = new AkeCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        $result = $this->createUpdater(new AkeDataFixture(), $catalog)->update();

        $this->assertSame(AkeDataFixture::LATEST, $result['catalog']['version']);
        $this->assertSame(5, $result['new_items']);

        // Saved in the pool: a fresh reader sees it, and the full tables replace the previous catalog
        $reader = new AkeCatalog($pool);
        $this->assertSame(AkeDataFixture::LATEST, $reader->getVersion());
        $this->assertSame('Delivery Guaranteed', $reader->find('wpn_funnel_0011')['name']);
        $this->assertNull($reader->find('wpn_old_0001'));
        $this->assertSame(
            [
                'id' => 'rerun_chr_aglina', 'category' => 'char', 'type' => AkeCatalog::CHAR_POOL_TYPE_RERUN, 'up' => ['chr_0013_aglina'], 'up_source' => 'irminsul',
                'runs' => [['start_at' => '2026-11-25 03:00:00', 'end_at' => '2026-12-16 16:59:59']],
            ],
            $reader->findPool('rerun_chr_aglina'),
        );
        $this->assertSame(AkeCatalog::WEAPON_POOL_TYPE_NORMAL, $reader->findPool('weaponbox_constant_1')['type']);
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
        $catalog = new AkeCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        try {
            $this->createUpdater(new AkeDataFixture($files), $catalog)->update();
            $this->fail('A failed build must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('previous catalog kept', $e->getMessage());
        }

        // The previous catalog predates the pools: it reads back with an empty pool list
        $this->assertSame(self::PREVIOUS_CATALOG + ['pools' => []], $catalog->all());
        $this->assertSame(self::PREVIOUS_CATALOG + ['pools' => []], new AkeCatalog($pool)->all());
    }

    public function testIrminsulDownStillSavesTheCatalogWithThePreviousRuns(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new AkeCatalog($pool);
        $this->createUpdater(new AkeDataFixture(), $catalog)->update();
        $saved = $catalog->findPool('rerun_chr_aglina');
        $this->assertSame('irminsul', $saved['up_source']);
        $this->assertCount(1, $saved['runs']);

        // The next week irminsul.gg is down: the new build keeps the stored runs and rate-ups
        $result = $this->createUpdater(new AkeDataFixture(), $catalog, EndfieldIrminsulFixture::down())->update();

        $this->assertSame(AkeDataFixture::LATEST, $result['catalog']['version']);
        $this->assertSame($saved, new AkeCatalog($pool)->findPool('rerun_chr_aglina'));
        $this->assertSame([], new AkeCatalog($pool)->findPool('standard')['runs']);
    }

    private function createUpdater(AkeDataFixture $fixture, AkeCatalog $catalog, ?EndfieldIrminsulFixture $irminsul = null): AkeCatalogUpdater
    {
        return new AkeCatalogUpdater($fixture->builder(irminsul: $irminsul, previous: $catalog), $catalog, new NullLogger());
    }
}
