<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\FillerSlot;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Pull numbers in the planner (the S of one 10-pull share its second) and the timeline-order tie-break of the merge.
 * The behaviour without numbers is covered, unchanged, by PullGapPlannerTest.
 */
class PullGapPlannerPullNumberTest extends TestCase
{
    private PullGapPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new PullGapPlanner();
    }

    public function testFillersBetweenTwoNumberedSOfOneSecondTakeThatSecondAndConsecutiveNumbers(): void
    {
        // Pull 40 is an S after 39 unknown pulls; pull 44 is an S of the same 10-pull
        $entries = [
            self::recorded(1, '2025-01-01 09:00:00'),
            self::manualS(20, '2025-01-01 12:00:00', 39, 40),
            self::manualS(21, '2025-01-01 12:00:00', 4, 44),
            self::recorded(2, '2025-01-01 13:00:00'),
        ];

        $plan = $this->planner->plan($entries);

        $this->assertSame([], $plan->warnings);
        $between = array_values(array_filter($plan->fillerCreates, static fn (FillerSlot $slot): bool => $slot->sId === 21));
        $this->assertSame(['2025-01-01 12:00:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00'], self::slotDates($between));
        $this->assertSame([41, 42, 43], array_map(static fn (FillerSlot $slot): ?int => $slot->pullNumber, $between));
        $this->assertSame('UTC', $between[0]->pulledAt->getTimezone()->getName());
        $before = array_values(array_filter($plan->fillerCreates, static fn (FillerSlot $slot): bool => $slot->sId === 20));
        $this->assertCount(37, $before, '39 declared, the recorded pull of 09:00 and the S itself');
        $this->assertSame([null], array_values(array_unique(array_map(static fn (FillerSlot $slot): ?int => $slot->pullNumber, $before))), 'Fillers placed by time stay unnumbered');

        $applied = self::apply($entries, $plan);
        $this->assertSame([40, 41, 42, 43, 44], array_values(array_filter(array_map(static fn (PullTimelineEntry $entry): ?int => $entry->pullNumber, $applied))));
        $this->assertTrue($this->planner->plan($applied)->isEmpty(), 'Idempotent');

        // The same rows without numbers: no room between the two S (the case the numbers solve)
        $unnumbered = $this->planner->plan([
            self::recorded(1, '2025-01-01 09:00:00'),
            self::manualS(20, '2025-01-01 12:00:00', 39),
            self::manualS(21, '2025-01-01 12:00:00', 4),
            self::recorded(2, '2025-01-01 13:00:00'),
        ]);
        $this->assertCount(1, $unnumbered->warnings);
        $this->assertStringContainsString('S #21', $unnumbered->warnings[0]);
        $this->assertSame([], array_values(array_filter($unnumbered->fillerCreates, static fn (FillerSlot $slot): bool => $slot->sId === 21)));
    }

    public function testNumberedSOneSecondApartGetTheirFillersInTheSecondOfTheEarlierOne(): void
    {
        $plan = $this->planner->plan([
            self::manualS(20, '2025-01-01 12:00:00', 1, 1),
            self::manualS(21, '2025-01-01 12:00:01', 3, 4),
        ]);

        $this->assertSame([], $plan->warnings);
        $this->assertSame(['2025-01-01 12:00:00', '2025-01-01 12:00:00'], self::slotDates($plan->fillerCreates));
        $this->assertSame([2, 3], array_map(static fn (FillerSlot $slot): ?int => $slot->pullNumber, $plan->fillerCreates));
    }

    public function testNumberedGapWithoutEnoughNumbersGivesAWarningAndNoFiller(): void
    {
        $plan = $this->planner->plan([
            self::manualS(20, '2025-01-01 12:00:00', 1, 40),
            self::manualS(21, '2025-01-01 12:00:00', 5, 42),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('No room for 4 unknown pull(s) before S #21', $plan->warnings[0]);
        $this->assertStringContainsString('room for 1 after the previous S', $plan->warnings[0]);

        // Numbers in the wrong order leave no room at all
        $reversed = $this->planner->plan([
            self::manualS(20, '2025-01-01 12:00:00', 1, 42),
            self::manualS(21, '2025-01-01 12:00:00', 2, 40),
        ]);
        $this->assertTrue($reversed->isEmpty());
        $this->assertStringContainsString('room for 0 after the previous S', $reversed->warnings[0]);
    }

    public function testANumberedGapNeedsBothBoundsNumbered(): void
    {
        // One second apart, so that no second mixes numbered and unnumbered rows
        foreach ([[40, null], [null, 44]] as [$first, $second]) {
            $plan = $this->planner->plan([
                self::manualS(20, '2025-01-01 12:00:00', 1, $first),
                self::manualS(21, '2025-01-01 12:00:01', 4, $second),
            ]);

            $this->assertTrue($plan->isEmpty());
            $this->assertCount(1, $plan->warnings);
            $this->assertStringContainsString('its neighbours are less than 2 seconds apart', $plan->warnings[0]);
        }
    }

    public function testASecondMixingNumberedAndUnnumberedRowsGivesAWarningAndNoNumberedFiller(): void
    {
        // An unnumbered recorded pull in the second of the two numbered S: their order is unknown
        $plan = $this->planner->plan([
            self::recorded(1, '2025-01-01 09:00:00'),
            self::recorded(2, '2025-01-01 12:00:00'),
            self::manualS(20, '2025-01-01 12:00:00', 2, 40),
            self::manualS(21, '2025-01-01 12:00:00', 4, 44),
        ]);

        $this->assertCount(2, $plan->warnings);
        $this->assertSame('The pulls of 2025-01-01 12:00:00 mix rows with and without a pull number: their order inside that second is unknown, so no filler is numbered there.', $plan->warnings[0]);
        $this->assertStringContainsString('No room for 3 unknown pull(s) before S #21', $plan->warnings[1]);
        $this->assertSame([], array_values(array_filter($plan->fillerCreates, static fn (FillerSlot $slot): bool => $slot->pullNumber !== null)));

        // Fillers do not make a second mixed: they are re-placed by the plan itself
        $withFillers = $this->planner->plan([
            self::recorded(1, '2025-01-01 09:00:00'),
            new PullTimelineEntry(90, self::date('2025-01-01 12:00:00'), PullOrigin::Filler, false, true),
            self::manualS(20, '2025-01-01 12:00:00', 2, 40),
            self::manualS(21, '2025-01-01 12:00:00', 4, 44),
        ]);
        $this->assertSame([], $withFillers->warnings);
    }

    public function testExistingFillersAreRenumberedAndMovedIntoTheNumberedGap(): void
    {
        $entries = [
            new PullTimelineEntry(90, self::date('2025-01-01 08:00:00'), PullOrigin::Filler, false, true),
            self::manualS(20, '2025-01-01 12:00:00', 1, 40),
            new PullTimelineEntry(91, self::date('2025-01-01 12:00:00'), PullOrigin::Filler, false, true, pullNumber: 43),
            new PullTimelineEntry(92, self::date('2025-01-01 12:00:00'), PullOrigin::Filler, false, true, pullNumber: 45),
            self::manualS(21, '2025-01-01 12:00:00', 4, 44),
        ];

        $plan = $this->planner->plan($entries);

        // Slots 41, 42, 43 in that second; fillers in order: #90 (08:00), #91 (43), #92 (45)
        $this->assertSame([90], array_keys($plan->fillerMoves));
        $this->assertSame('2025-01-01 12:00:00', $plan->fillerMoves[90]->format('Y-m-d H:i:s'));
        $this->assertSame([90 => 41, 91 => 42, 92 => 43], $plan->fillerPullNumbers);
        $this->assertSame([], $plan->fillerCreates);
        $this->assertSame([], $plan->fillerDeletes);
        $this->assertFalse($plan->isEmpty());
        $this->assertTrue($this->planner->plan(self::apply($entries, $plan))->isEmpty());

        // A renumbering alone is a change too
        $this->assertFalse((new FillerPlan(fillerPullNumbers: [91 => null]))->isEmpty());
    }

    public function testMergeTieGoesToTheEarliestCandidateOfTheTimeline(): void
    {
        // One 10-pull with two copies of the same item, inserted newest first: the first copy has the higher id
        $entries = [
            self::manualS(5, '2025-01-01 10:00:00', 50, itemId: '1046'),
            self::manualS(6, '2025-01-01 10:00:00', 4, itemId: '1046'),
            self::recordedS(60, '2025-01-01 12:00:00', itemId: '1046'),
            self::recordedS(56, '2025-01-01 12:00:00', itemId: '1046'),
        ];

        $plan = $this->planner->plan($entries);

        $this->assertSame([5 => 60, 6 => 56], $plan->merges, 'First manual copy with the first recorded copy');
        $this->assertSame([60 => 50, 56 => 4], $plan->declaredPityUpdates);

        // A single manual copy takes the first recorded one too
        $single = $this->planner->plan([$entries[0], $entries[2], $entries[3]]);
        $this->assertSame([5 => 60], $single->merges);
    }

    public function testPlanIsIdempotentOnRandomTimelinesWithNumberedTenPulls(): void
    {
        mt_srand(20261122);
        $numberedGaps = 0;
        for ($run = 0; $run < 300; ++$run) {
            $entries = self::randomNumberedTimeline();
            $plan = $this->planner->plan($entries);
            $applied = self::apply($entries, $plan);
            $second = $this->planner->plan($applied);

            $this->assertTrue($second->isEmpty(), \sprintf('Run %d is not idempotent: %s', $run, json_encode($second)));
            $this->assertSame($plan->warnings, $second->warnings, \sprintf('Run %d changed its warnings', $run));

            // Between two numbered S of one second: exactly declared - 1 fillers, in that second, numbered in sequence
            $previous = null;
            $previousIndex = -1;
            foreach ($applied as $index => $entry) {
                if (!$entry->isS || $entry->origin === PullOrigin::Filler) {
                    continue;
                }
                if ($previous !== null && $previous->pullNumber !== null && $entry->pullNumber !== null
                    && $previous->pulledAt->getTimestamp() === $entry->pulledAt->getTimestamp()) {
                    ++$numberedGaps;
                    $between = array_values(array_filter(
                        \array_slice($applied, $previousIndex + 1, $index - $previousIndex - 1),
                        static fn (PullTimelineEntry $e): bool => $e->origin === PullOrigin::Filler,
                    ));
                    $this->assertSame($entry->declaredPity - 1, \count($between), \sprintf('Run %d, S #%d', $run, $entry->id));
                    $expected = $entry->pullNumber - $previous->pullNumber > 1 ? range($previous->pullNumber + 1, $entry->pullNumber - 1) : [];
                    $this->assertSame($expected, array_map(static fn (PullTimelineEntry $e): ?int => $e->pullNumber, $between));
                    foreach ($between as $filler) {
                        $this->assertSame($entry->pulledAt->getTimestamp(), $filler->pulledAt->getTimestamp());
                    }
                }
                $previous = $entry;
                $previousIndex = $index;
            }
        }

        $this->assertGreaterThan(100, $numberedGaps);
    }

    /**
     * Applies a plan like a provider does, then orders the rows like the providers: date, unnumbered rows first, then
     * by number (stable, so other rows sharing a second keep their order).
     *
     * @param list<PullTimelineEntry> $entries
     * @return list<PullTimelineEntry>
     */
    private static function apply(array $entries, FillerPlan $plan): array
    {
        $nextId = max([0, ...array_map(static fn (PullTimelineEntry $entry): int => $entry->id, $entries)]) + 1;

        $result = [];
        foreach ($entries as $entry) {
            if (isset($plan->merges[$entry->id]) || \in_array($entry->id, $plan->fillerDeletes, true)) {
                continue;
            }
            $result[] = new PullTimelineEntry(
                id: $entry->id,
                pulledAt: $plan->fillerMoves[$entry->id] ?? $entry->pulledAt,
                origin: $entry->origin,
                isS: $entry->isS,
                countsAsPull: $entry->countsAsPull,
                declaredPity: $plan->declaredPityUpdates[$entry->id] ?? $entry->declaredPity,
                itemId: $entry->itemId,
                name: $entry->name,
                pullNumber: \array_key_exists($entry->id, $plan->fillerPullNumbers) ? $plan->fillerPullNumbers[$entry->id] : $entry->pullNumber,
            );
        }
        foreach ($plan->fillerCreates as $slot) {
            $result[] = new PullTimelineEntry($nextId++, $slot->pulledAt, PullOrigin::Filler, false, true, pullNumber: $slot->pullNumber);
        }

        usort($result, static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => [$a->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($a->pullNumber)]
            <=> [$b->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($b->pullNumber)]);

        return $result;
    }

    /**
     * A banner as an S-rank history import would leave it: recorded pulls in their own seconds, and 10-pulls known only
     * by their S, numbered, one to three per second and at most 9 pulls apart, plus stale fillers anywhere.
     *
     * @return list<PullTimelineEntry>
     */
    private static function randomNumberedTimeline(): array
    {
        $time = mt_rand(1_700_000_000, 1_750_000_000);
        $entries = [];
        $nextId = 1;
        $pullNumber = mt_rand(1, 50);
        $count = mt_rand(1, 25);
        for ($i = 0; $i < $count; ++$i) {
            $time += [1, 2, 3, 60, 3600, 86400 * 3][mt_rand(0, 5)];
            $at = (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('UTC'));
            $roll = mt_rand(1, 100);
            if ($roll <= 40) {
                // One 10-pull: its first S closes a window of unknown length, the next ones are a few pulls later
                $pullNumber += mt_rand(1, 90);
                $entries[] = new PullTimelineEntry(1000 + $nextId++, $at, PullOrigin::Manual, true, true, mt_rand(1, 90), null, 'Furina', $pullNumber);
                $copies = mt_rand(1, 2);
                for ($k = 0; $k < $copies; ++$k) {
                    $distance = mt_rand(1, 9);
                    $pullNumber += $distance;
                    $entries[] = new PullTimelineEntry(1000 + $nextId++, $at, PullOrigin::Manual, true, true, $distance, null, 'Raiden', $pullNumber);
                }
            } elseif ($roll <= 55) {
                $entries[] = new PullTimelineEntry($nextId++, $at, PullOrigin::Recorded, true, true, mt_rand(0, 2) === 0 ? mt_rand(1, 90) : null, null, 'Neuvillette');
            } elseif ($roll <= 65) {
                $entries[] = new PullTimelineEntry(5000 + $nextId++, $at->setTimestamp($time + mt_rand(-200000, 200000)), PullOrigin::Filler, false, true, pullNumber: mt_rand(0, 1) === 0 ? mt_rand(1, 500) : null);
            } else {
                $entries[] = new PullTimelineEntry($nextId++, $at, PullOrigin::Recorded, false, true);
            }
        }

        usort($entries, static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => [$a->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($a->pullNumber)]
            <=> [$b->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($b->pullNumber)]);

        return $entries;
    }

    /**
     * @param list<FillerSlot> $slots
     * @return list<string>
     */
    private static function slotDates(array $slots): array
    {
        return array_map(static fn (FillerSlot $slot): string => $slot->pulledAt->format('Y-m-d H:i:s'), $slots);
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private static function recorded(int $id, string $at): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Recorded, false, true);
    }

    private static function recordedS(int $id, string $at, ?string $itemId = null): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Recorded, true, true, null, $itemId, 'Furina');
    }

    private static function manualS(int $id, string $at, int $declaredPity, ?int $pullNumber = null, ?string $itemId = null): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Manual, true, true, $declaredPity, $itemId, 'Furina', $pullNumber);
    }
}
