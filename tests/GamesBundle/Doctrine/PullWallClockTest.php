<?php

namespace App\Tests\GamesBundle\Doctrine;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pull dates are wall-clock values: they must survive a database round trip unchanged even in the hour
 * that does not exist in Europe/Rome when DST starts, otherwise the missing-pulls realign keeps moving
 * the filler rows placed there.
 */
class PullWallClockTest extends KernelTestCase
{
    /** Last Sunday of March 2026: 02:00–03:00 does not exist in Europe/Rome */
    private const string DST_DAY = '2026-03-29';

    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    public function testPullDatesAreMappedOnTheWallClockType(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ([HoyoversePull::class, GryphlinePull::class, PerfectworldPull::class] as $class) {
            $mapping = $entityManager->getClassMetadata($class)->getFieldMapping('pulledAt');
            $this->assertSame(WallClockDateTimeImmutableType::NAME, $mapping->type, $class);
        }
    }

    public function testHoyoverseRealignIsStableForFillersInTheDstGap(): void
    {
        $game = (new \ReflectionClass(GenshinImpactService::class))->newInstanceWithoutConstructor();
        $calculator = new HoyoversePullStatsCalculator(new PullGapPlanner());
        $rows = [
            $this->row(1, '01:00:00', 5),
            $this->row(2, '03:10:00', 3),
            $this->row(3, '03:20:00', 5, declaredPity: 40),
        ];

        $this->assertStableAfterRoundTrip(
            static fn (array $rows): array => $calculator->toTimelineEntries($rows, $game->getRarityConfig()),
            $rows,
        );
    }

    public function testGryphlineRealignIsStableForFillersInTheDstGap(): void
    {
        $game = (new \ReflectionClass(ArknightsEndfieldService::class))->newInstanceWithoutConstructor();
        $calculator = new GryphlinePullStatsCalculator(new PullGapPlanner());
        $rows = [
            $this->row(1, '01:00:00', 6),
            $this->row(2, '03:10:00', 4),
            $this->row(3, '03:20:00', 6, declaredPity: 40),
        ];

        $this->assertStableAfterRoundTrip(
            static fn (array $rows): array => $calculator->toTimelineEntries($rows, $game->getRarityConfig()),
            $rows,
        );
    }

    public function testThePlainDoctrineTypeWouldShiftTheFillers(): void
    {
        // Guards the test above: with the default Doctrine type the same rows are re-planned on every run
        $game = (new \ReflectionClass(GenshinImpactService::class))->newInstanceWithoutConstructor();
        $calculator = new HoyoversePullStatsCalculator(new PullGapPlanner());
        $toEntries = static fn (array $rows): array => $calculator->toTimelineEntries($rows, $game->getRarityConfig());
        $rows = $this->applyPlan(
            [$this->row(1, '01:00:00', 5), $this->row(2, '03:10:00', 3), $this->row(3, '03:20:00', 5, declaredPity: 40)],
            (new PullGapPlanner())->plan($toEntries([$this->row(1, '01:00:00', 5), $this->row(2, '03:10:00', 3), $this->row(3, '03:20:00', 5, declaredPity: 40)])),
        );

        $reloaded = $this->roundTrip($rows, new DateTimeImmutableType());

        $this->assertFalse((new PullGapPlanner())->plan($toEntries($reloaded))->isEmpty());
    }

    /**
     * @param callable(list<array<string, mixed>>): list<\App\GamesBundle\Model\Pull\PullTimelineEntry> $toEntries
     * @param list<array<string, mixed>> $rows
     */
    private function assertStableAfterRoundTrip(callable $toEntries, array $rows): void
    {
        $planner = new PullGapPlanner();
        $plan = $planner->plan($toEntries($rows));
        $this->assertCount(38, $plan->fillerCreates);

        $withFillers = $this->applyPlan($rows, $plan);
        $inGap = array_filter($withFillers, static fn (array $row): bool => $row['origin'] === PullOrigin::Filler
            && str_starts_with($row['pulledAt']->format('Y-m-d H'), self::DST_DAY . ' 02'));
        $this->assertNotEmpty($inGap, 'Some fillers must land in the skipped hour');

        $reloaded = $this->roundTrip($withFillers, Type::getType(WallClockDateTimeImmutableType::NAME));

        foreach ($withFillers as $i => $row) {
            $this->assertSame($row['pulledAt']->format('Y-m-d H:i:s'), $reloaded[$i]['pulledAt']->format('Y-m-d H:i:s'));
        }
        $this->assertTrue($planner->plan($toEntries($reloaded))->isEmpty(), 'A second realign after the round trip changes nothing');
    }

    /**
     * Writes and reads back every pulledAt through a Doctrine type, as a flush followed by a query would.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function roundTrip(array $rows, Type $type): array
    {
        $platform = new MySQL80Platform();

        return array_map(static function (array $row) use ($type, $platform): array {
            $row['pulledAt'] = $type->convertToPHPValue($type->convertToDatabaseValue($row['pulledAt'], $platform), $platform);

            return $row;
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyPlan(array $rows, FillerPlan $plan): array
    {
        $nextId = 100;
        foreach ($plan->fillerCreates as $slot) {
            $rows[] = $this->row($nextId++, null, 0, origin: PullOrigin::Filler, pulledAt: $slot->pulledAt);
        }
        usort($rows, static fn (array $a, array $b): int => [$a['pulledAt'], $a['id']] <=> [$b['pulledAt'], $b['id']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        ?string $time,
        int $rarity,
        ?int $declaredPity = null,
        PullOrigin $origin = PullOrigin::Recorded,
        ?\DateTimeImmutable $pulledAt = null,
    ): array {
        return [
            'id' => $id,
            'pullId' => (string) $id,
            'seqId' => (string) $id,
            'gachaType' => '301',
            'poolType' => 'E_CharacterGachaPoolType_Special',
            'poolCategory' => 'char',
            'itemId' => null,
            'name' => $rarity >= 5 ? 'Item ' . $id : 'Unknown',
            'rarity' => $rarity,
            'origin' => $origin,
            'declaredPity' => $declaredPity,
            'pulledAt' => $pulledAt ?? WallClockDateTimeImmutableType::fromString(self::DST_DAY . ' ' . $time),
        ];
    }
}
