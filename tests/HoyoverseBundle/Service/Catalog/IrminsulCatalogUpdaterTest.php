<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class IrminsulCatalogUpdaterTest extends TestCase
{
    private const array PREVIOUS_CATALOG = [
        'fetched_at' => '2026-11-16T06:10:00+01:00',
        'items' => [
            '1071' => ['id' => '1071', 'name' => 'Caesar', 'display_name' => 'Caesar King', 'rarity' => 5, 'category' => 'character', 'release' => null],
        ],
        'phases' => [
            [
                'id' => 10004,
                'category' => 'character',
                'version' => '1.2.1',
                'title' => null,
                'start' => ['at' => '2024-09-25 11:00:00', 'offset' => 8],
                'end' => ['at' => '2024-10-16 11:59:59', 'offset' => null],
                'up' => ['1071'],
                'up_a' => [],
            ],
        ],
    ];

    public function testBuildsAndSavesTheCatalog(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new ZzzCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        $result = $this->createUpdater(new IrminsulFixture(), $catalog)->update();

        $this->assertSame(12, $result['items']);
        $this->assertSame(4, $result['phases']);
        $this->assertSame($catalog->getFetchedAt(), $result['fetched_at']);
        $this->assertNotSame(self::PREVIOUS_CATALOG['fetched_at'], $result['fetched_at']);

        // Saved in the pool: a fresh reader sees it, and it replaces the previous catalog
        $reader = new ZzzCatalog($pool);
        $this->assertTrue($reader->isLoaded());
        $this->assertCount(2, $reader->getPhases('weapon'));
        $this->assertSame('Burnice', $reader->findItemByName('Burnice White')['name']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function failedBuildProvider(): iterable
    {
        yield 'source unavailable' => [['v2/zzz/banner-characters.json' => null]];
        yield 'no usable phase' => [['v2/zzz/banner-weapons.json' => [['id' => 20004, 'version' => '1.2.1']]]];
    }

    /**
     * @param array<string, mixed> $files
     */
    #[DataProvider('failedBuildProvider')]
    public function testFailedBuildKeepsThePreviousCatalog(array $files): void
    {
        $pool = new ArrayAdapter();
        $catalog = new ZzzCatalog($pool);
        $catalog->save(self::PREVIOUS_CATALOG);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Zenless Zone Zero catalog update aborted, previous catalog kept', $this->arrayHasKey('error'));

        try {
            $this->createUpdater(new IrminsulFixture($files), $catalog, $logger)->update();
            $this->fail('A failed build must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Zenless Zone Zero catalog update aborted, previous catalog kept: ', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }

        $this->assertSame(self::PREVIOUS_CATALOG, $catalog->all());
        $this->assertSame(self::PREVIOUS_CATALOG, new ZzzCatalog($pool)->all());
    }

    public function testABuildWithFewerPhasesThanTheStoredCatalogIsRefused(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new ZzzCatalog($pool);
        $this->createUpdater(new IrminsulFixture(), $catalog)->update();
        $stored = $catalog->all();
        $this->assertCount(4, $stored['phases']);

        // A truncated but valid weapon file: one phase left instead of two
        $truncated = new IrminsulFixture(['v2/zzz/banner-weapons.json' => [[
            'id' => 20004, 'version' => '1.2.1', 'start' => '2024-09-25 11:00:00 UTC+8', 'end' => '2024-10-16 11:59:59', 'rateUps' => [],
        ]]]);
        try {
            $this->createUpdater($truncated, $catalog)->update();
            $this->fail('A build losing phases must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fewer than the 4 of the stored catalog', $e->getMessage());
        }

        $this->assertSame($stored, new ZzzCatalog($pool)->all(), 'The previous catalog stays');
    }

    private function createUpdater(IrminsulFixture $fixture, ZzzCatalog $catalog, ?LoggerInterface $logger = null): ZzzCatalogUpdater
    {
        return new ZzzCatalogUpdater(
            new ZzzCatalogBuilder($fixture->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger()),
            $catalog,
            $logger ?? new NullLogger(),
        );
    }
}
