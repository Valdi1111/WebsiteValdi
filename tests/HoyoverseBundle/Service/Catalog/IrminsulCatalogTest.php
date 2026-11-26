<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\HoyoverseBundle\Service\Catalog\AbstractIrminsulCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Read API of the HoYoverse banner catalogs (AbstractIrminsulCatalog).
 */
class IrminsulCatalogTest extends TestCase
{
    private const array CATALOG = [
        'fetched_at' => '2026-11-23T06:05:00+01:00',
        'items' => [
            '1217' => ['id' => '1217', 'name' => 'Imbibitor Lunae', 'display_name' => 'Dan Heng • Imbibitor Lunae', 'rarity' => 5, 'category' => 'character', 'release' => null],
            '8001' => ['id' => '8001', 'name' => 'Trailblazer', 'display_name' => 'Trailblazer (Destruction)', 'rarity' => 5, 'category' => 'character', 'release' => null],
            '8003' => ['id' => '8003', 'name' => 'Trailblazer', 'display_name' => 'Trailblazer (Preservation)', 'rarity' => 5, 'category' => 'character', 'release' => null],
            '23010' => ['id' => '23010', 'name' => 'Before Dawn', 'display_name' => 'Before Dawn', 'rarity' => 5, 'category' => 'weapon', 'release' => null],
        ],
        'phases' => [
            [
                'id' => 10010,
                'category' => 'character',
                'version' => '1.5.1',
                'title' => null,
                'start' => ['at' => '2023-11-15 11:00:00', 'offset' => 8],
                'end' => ['at' => '2023-12-06 11:59:59', 'offset' => null],
                'up' => ['1217'],
                'up_a' => [],
            ],
            [
                'id' => 20001,
                'category' => 'weapon',
                'version' => '1.0.2',
                'title' => null,
                'start' => ['at' => '2023-05-17 12:00:00', 'offset' => null],
                'end' => ['at' => '2023-06-06 14:59:59', 'offset' => null],
                'up' => ['23010'],
                'up_a' => [],
            ],
            [
                'id' => 10011,
                'category' => 'character',
                'version' => '1.5.2',
                'title' => null,
                'start' => ['at' => '2023-12-06 12:00:00', 'offset' => null],
                'end' => ['at' => '2023-12-26 14:59:59', 'offset' => null],
                'up' => ['1217'],
                'up_a' => [],
            ],
        ],
    ];

    public function testAnEmptyPoolIsNotLoaded(): void
    {
        $catalog = new GiCatalog(new ArrayAdapter());

        $this->assertFalse($catalog->isLoaded());
        $this->assertNull($catalog->getFetchedAt());
        $this->assertSame(['fetched_at' => null, 'items' => [], 'phases' => []], $catalog->all());
        $this->assertNull($catalog->findItem('10000030'));
        $this->assertNull($catalog->findItemByName('Zhongli'));
        $this->assertSame([], $catalog->getPhases('character'));
    }

    public function testSavedCatalogIsReadBackByAFreshReader(): void
    {
        $pool = new ArrayAdapter();
        new HsrCatalog($pool)->save(self::CATALOG);

        $catalog = new HsrCatalog($pool);
        $this->assertTrue($catalog->isLoaded());
        $this->assertSame(self::CATALOG, $catalog->all());
        $this->assertSame('2026-11-23T06:05:00+01:00', $catalog->getFetchedAt());
        $this->assertSame('Before Dawn', $catalog->findItem(' 23010 ')['name']);
        $this->assertSame([10010, 10011], array_column($catalog->getPhases('character'), 'id'));
        $this->assertSame([20001], array_column($catalog->getPhases('weapon'), 'id'));
        $this->assertSame([], $catalog->getPhases('bangboo'));
    }

    public function testItemsAreFoundByNormalizedNameOrDisplayNameWhenUnique(): void
    {
        $catalog = new HsrCatalog(new ArrayAdapter());
        $catalog->save(self::CATALOG);

        $this->assertSame('1217', $catalog->findItemByName('Dan Heng Imbibitor Lunae')['id']);
        $this->assertSame('1217', $catalog->findItemByName('imbibitor lunae')['id']);
        $this->assertSame('8003', $catalog->findItemByName('Trailblazer (Preservation)')['id']);
        // Two items are named "Trailblazer": ambiguous
        $this->assertNull($catalog->findItemByName('Trailblazer'));
        $this->assertNull($catalog->findItemByName('• !'));
        $this->assertNull($catalog->findItemByName('Kafka'));
    }

    public function testMalformedEntriesAreDropped(): void
    {
        $catalog = new ZzzCatalog(new ArrayAdapter());
        $catalog->save([
            'fetched_at' => '',
            'items' => [
                '1071' => ['name' => 'Caesar', 'display_name' => '', 'rarity' => '5', 'category' => 'bangboo'],
                '1171' => ['id' => '1171', 'display_name' => 'Burnice White'],
                ' ' => ['name' => 'Blank id'],
                '1031' => 'Nicole',
            ],
            'phases' => [
                ['id' => 10004, 'category' => 'character', 'version' => '1.2.1', 'title' => '', 'start' => ['at' => '2024-09-25 11:00:00', 'offset' => 8], 'end' => ['at' => '2024-10-16 11:59:59'], 'up' => ['1071', 1071, 1111, '', null], 'up_a' => 'nope'],
                ['id' => 10005, 'category' => 'character', 'version' => '1.2.2', 'start' => ['at' => '2024-10-16T12:00:00', 'offset' => null], 'end' => ['at' => '2024-11-05 14:59:59', 'offset' => null]],
                ['id' => 10006, 'category' => 'bangboo', 'version' => '1.3.1', 'start' => ['at' => '2024-11-06 11:00:00', 'offset' => 8], 'end' => ['at' => '2024-11-27 11:59:59', 'offset' => null]],
                ['id' => 10007, 'category' => 'weapon', 'version' => '1.3.2', 'start' => ['at' => '2024-11-27 12:00:00', 'offset' => null]],
                'not a phase',
            ],
        ]);

        $this->assertSame(
            ['1071' => ['id' => '1071', 'name' => 'Caesar', 'display_name' => null, 'rarity' => null, 'category' => 'character', 'release' => null]],
            $catalog->all()['items'],
        );
        $this->assertSame([
            [
                'id' => 10004,
                'category' => 'character',
                'version' => '1.2.1',
                'title' => null,
                'start' => ['at' => '2024-09-25 11:00:00', 'offset' => 8],
                'end' => ['at' => '2024-10-16 11:59:59', 'offset' => null],
                'up' => ['1071', '1111'],
                'up_a' => [],
            ],
        ], $catalog->getPhases('character'));
        $this->assertNull($catalog->getFetchedAt());
        $this->assertFalse($catalog->isLoaded());
    }

    public function testSaveRefreshesTheMemoAndResetRereadsThePool(): void
    {
        $pool = new ArrayAdapter();
        $reader = new GiCatalog($pool);
        $this->assertFalse($reader->isLoaded());

        // Written by another instance (the worker): the memo of the reader stays until reset()
        new GiCatalog($pool)->save(self::CATALOG);
        $this->assertFalse($reader->isLoaded());
        $reader->reset();
        $this->assertTrue($reader->isLoaded());
        $this->assertSame('1217', $reader->findItemByName('Dan Heng • Imbibitor Lunae')['id']);

        // Its own save refreshes the memo and the name index
        $reader->save(['fetched_at' => '2026-11-30T06:05:00+01:00', 'items' => ['1102' => ['name' => 'Seele', 'rarity' => 5]], 'phases' => self::CATALOG['phases']]);
        $this->assertNull($reader->findItemByName('Imbibitor Lunae'));
        $this->assertSame('1102', $reader->findItemByName('Seele')['id']);
    }

    public function testEachGameHasItsSlugAndKey(): void
    {
        $this->assertSame(['genshin', 'hsr', 'zzz'], [GiCatalog::getGameSlug(), HsrCatalog::getGameSlug(), ZzzCatalog::getGameSlug()]);
        $keys = array_map(static fn (AbstractIrminsulCatalog $catalog): string => $catalog->getCacheKey(), [new GiCatalog(new ArrayAdapter()), new HsrCatalog(new ArrayAdapter()), new ZzzCatalog(new ArrayAdapter())]);
        $this->assertSame(['gi_catalog_v1', 'hsr_catalog_v1', 'zzz_catalog_v1'], $keys);
    }
}
