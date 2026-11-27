<?php

namespace App\Tests\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\GamesBundle\Service\PullItemIconPathCodec;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PullCardSectionFactoryTest extends TestCase
{
    private PullCardSectionFactory $factory;

    protected function setUp(): void
    {
        $resolver = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://example.com/icon.png';
            }
        };

        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willReturnCallback(
            static fn (string $path, string $filter): string => sprintf('https://localhost/media/cache/%s/%s', $filter, $path)
        );

        $this->factory = new PullCardSectionFactory(new PullItemIconUrlBuilder(
            $cacheManager,
            new PullItemIconPathCodec(),
            new PullItemIconResolverManager([$resolver]),
        ));
    }

    /**
     * @param ?int $sCount s_count of the summary, null when it has none
     */
    #[DataProvider('limitProvider')]
    public function testShowsTheNewestSPullsUpToTheLimit(?int $sCount, int $listed, ?int $limit, int $expectedShown, int $expectedHidden): void
    {
        $rows = [];
        for ($i = 1; $i <= $listed; ++$i) {
            $rows[] = self::sRow('S'.$i, $i);
        }
        $summary = ['recent_s_pulls' => $rows];
        if ($sCount !== null) {
            $summary['s_count'] = $sCount;
        }

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, $limit);

        $expectedNames = [];
        for ($i = 1; $i <= $expectedShown; ++$i) {
            $expectedNames[] = 'S'.$i;
        }
        $this->assertSame($expectedNames, self::names($section->items), 'The newest S pulls come first');
        $this->assertSame($expectedHidden, $section->hiddenCount);
        $this->assertSame($sCount ?? $listed, $section->sCount);
    }

    /**
     * @return iterable<string, array{?int, int, ?int, int, int}>
     */
    public static function limitProvider(): iterable
    {
        yield 'capped by the limit' => [5, 5, 3, 3, 2];
        yield 'limit above the count' => [5, 5, 10, 5, 0];
        yield 'limit null shows them all' => [5, 5, null, 5, 0];
        yield 'limit zero shows none' => [5, 5, 0, 0, 5];
        yield 'negative limit shows none' => [5, 5, -1, 0, 5];
        yield 'no S pull' => [0, 0, 3, 0, 0];
        yield 'S count larger than the list given' => [12, 5, 3, 3, 9];
        // The S count tells how many exist: those missing from a capped list are still counted as hidden
        yield 'S count larger than the list, limit null' => [12, 5, null, 5, 7];
        yield 'S count smaller than the list' => [2, 5, 3, 3, 2];
        yield 'no S count in the summary' => [null, 4, 2, 2, 2];
    }

    public function testLimitNullShowsEveryPullWithoutHiddenOnes(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = self::sRow('S'.$i, $i);
        }

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, ['s_count' => 30, 'recent_s_pulls' => $rows], null);

        $this->assertCount(30, $section->items);
        $this->assertSame('S1', $section->items[0]->name);
        $this->assertSame('S30', $section->items[29]->name);
        $this->assertSame(0, $section->hiddenCount);
    }

    public function testCountsTheRateUpOverEverySPullNotOnlyTheShownOnes(): void
    {
        $summary = [
            's_count' => 6,
            'recent_s_pulls' => [
                self::sRow('A', 70, ['is_up' => true]),
                self::sRow('B', 80, ['is_up' => false]),
                // Beyond the limit: still counted
                self::sRow('C', 60, ['is_up' => true]),
                self::sRow('D', 50, ['is_up' => true]),
                // Unknown rate-up: left out of both counts
                self::sRow('E', 40, ['is_up' => null]),
                self::sRow('F', 30),
            ],
        ];

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, 2);

        $this->assertSame(3, $section->upCount);
        $this->assertSame(4, $section->upKnown);
        $this->assertSame(['A', 'B'], self::names($section->items));
        $this->assertTrue($section->items[0]->isUp);
        $this->assertFalse($section->items[1]->isUp);
    }

    public function testKeepsAnUnknownRateUpAsNullOnTheItem(): void
    {
        $summary = ['recent_s_pulls' => [self::sRow('A', 70, ['is_up' => null]), self::sRow('B', 80)]];

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, null);

        $this->assertNull($section->items[0]->isUp);
        $this->assertNull($section->items[1]->isUp);
        $this->assertSame(0, $section->upCount);
        $this->assertSame(0, $section->upKnown);
    }

    public function testMapsTheFlagsOfEachSPull(): void
    {
        $summary = [
            'recent_s_pulls' => [
                self::sRow('Manual', 75, ['origin' => 'manual', 'declared_pity' => 75]),
                self::sRow('Official', 60, ['origin' => 'official', 'declared_pity' => 60]),
                self::sRow('Free', null, ['is_free' => true]),
                self::sRow('Recorded', 81, ['origin' => 'recorded', 'declared_pity' => null, 'is_free' => false]),
                // Only a true is_free counts
                self::sRow('Truthy', 10, ['is_free' => 1]),
            ],
        ];

        $items = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, null)->items;

        $this->assertFlags($items[0], isManual: true, hasDeclaredPity: true, isFree: false);
        $this->assertFlags($items[1], isManual: false, hasDeclaredPity: true, isFree: false);
        $this->assertFlags($items[2], isManual: false, hasDeclaredPity: false, isFree: true);
        $this->assertFlags($items[3], isManual: false, hasDeclaredPity: false, isFree: false);
        $this->assertFlags($items[4], isManual: false, hasDeclaredPity: false, isFree: false);

        $this->assertSame(75, $items[0]->pity);
        $this->assertNull($items[2]->pity, 'A row without pity keeps it unknown');
    }

    public function testTheSPullsArgumentOverridesRecentSPulls(): void
    {
        $summary = [
            's_count' => 3,
            // Capped list of the summary (NTE)
            'recent_s_pulls' => [self::sRow('Capped', 10, ['is_up' => false])],
        ];
        $sPulls = [
            self::sRow('X', 70, ['is_up' => true]),
            self::sRow('Y', 80, ['is_up' => true]),
            self::sRow('Z', 90, ['is_up' => false]),
        ];

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, 2, $sPulls);

        $this->assertSame(['X', 'Y'], self::names($section->items));
        $this->assertSame(1, $section->hiddenCount);
        $this->assertSame(2, $section->upCount);
        $this->assertSame(3, $section->upKnown);
    }

    public function testCopiesTheTotalsOfTheSummary(): void
    {
        $summary = [
            'name' => 'Character Event Warp',
            'total_pulls' => 512,
            's_count' => 1,
            'avg_s_pity' => 71.25,
            's_pity' => 42,
            's_max_pity' => 90,
            'recent_s_pulls' => [self::sRow('Kafka', 71)],
        ];

        $section = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, 10);

        $this->assertSame('Character Event Warp', $section->name);
        $this->assertSame(512, $section->totalPulls);
        $this->assertSame(1, $section->sCount);
        $this->assertSame(71.25, $section->avgSPity);
        $this->assertSame(42, $section->sPity);
        $this->assertSame(90, $section->sMaxPity);
    }

    public function testAnEmptySummaryGivesAnEmptySection(): void
    {
        $section = $this->factory->fromSummary(HonkaiStarRailService::class, [], 10);

        $this->assertSame('', $section->name);
        $this->assertSame(0, $section->totalPulls);
        $this->assertSame(0, $section->sCount);
        $this->assertSame([], $section->items);
        $this->assertSame(0, $section->hiddenCount);
        $this->assertSame(90, $section->sMaxPity, 'The max pity defaults to 90');
        $this->assertNull($section->pullLabel);
    }

    #[DataProvider('pullLabelProvider')]
    public function testTakesThePullLabelOfTheSummary(mixed $pullLabel, ?string $expected): void
    {
        $summary = ['recent_s_pulls' => []];
        if ($pullLabel !== 'absent') {
            $summary['pull_label'] = $pullLabel;
        }

        $this->assertSame($expected, $this->factory->fromSummary(HonkaiStarRailService::class, $summary, 10)->pullLabel);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function pullLabelProvider(): iterable
    {
        yield 'own label' => ['Arcs', 'Arcs'];
        yield 'absent' => ['absent', null];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'not a string' => [5, null];
    }

    #[DataProvider('maxPityProvider')]
    public function testTheMaxPityIsNeverBelowOne(mixed $maxPity, int $expected): void
    {
        $section = $this->factory->fromSummary(HonkaiStarRailService::class, ['s_max_pity' => $maxPity], 10);

        $this->assertSame($expected, $section->sMaxPity);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function maxPityProvider(): iterable
    {
        yield 'regular' => [80, 80];
        yield 'one' => [1, 1];
        yield 'zero' => [0, 1];
        yield 'negative' => [-5, 1];
        yield 'null falls back to 90' => [null, 90];
    }

    public function testBuildsTheIconPathOfRowsWithAnItem(): void
    {
        $summary = [
            'recent_s_pulls' => [
                self::sRow('March 7th', 50, ['item_id' => '1001', 'item_type' => 'Character']),
                // Unknown pull added by the realign step: nothing to look up
                self::sRow('Unknown', 40, ['item_id' => null, 'item_type' => 'Unknown', 'origin' => 'filler']),
                self::sRow('Kafka', 30, ['item_id' => null, 'item_type' => 'Character']),
            ],
        ];

        $items = $this->factory->fromSummary(HonkaiStarRailService::class, $summary, null)->items;

        $this->assertSame('hkrpg_global/id/character/1001.webp', $items[0]->iconPath);
        $this->assertNull($items[1]->iconPath);
        $this->assertNotNull($items[2]->iconPath, 'A row without item id is looked up by name');
        $this->assertStringStartsWith('hkrpg_global/name/character/', $items[2]->iconPath);
    }

    public function testGivesNoIconPathForAGameWithoutIconResolver(): void
    {
        $summary = ['recent_s_pulls' => [self::sRow('Furina', 50, ['item_id' => '10000089', 'item_type' => 'Character'])]];

        $items = $this->factory->fromSummary(GenshinImpactService::class, $summary, null)->items;

        $this->assertCount(1, $items);
        $this->assertNull($items[0]->iconPath);
    }

    private function assertFlags(PullCardItem $item, bool $isManual, bool $hasDeclaredPity, bool $isFree): void
    {
        $this->assertSame($isManual, $item->isManual, $item->name.': isManual');
        $this->assertSame($hasDeclaredPity, $item->hasDeclaredPity, $item->name.': hasDeclaredPity');
        $this->assertSame($isFree, $item->isFree, $item->name.': isFree');
    }

    /**
     * S row shaped like the recent_s_pulls of the pull stats calculators.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function sRow(string $name, ?int $pity, array $extra = []): array
    {
        return $extra + [
            'name' => $name,
            'item_id' => '1001',
            'item_type' => 'Character',
            'rarity' => 5,
            'pity' => $pity,
            'origin' => 'recorded',
        ];
    }

    /**
     * @param list<PullCardItem> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(static fn (PullCardItem $item): string => $item->name, $items);
    }
}
