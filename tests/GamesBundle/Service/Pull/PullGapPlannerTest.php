<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\FillerSlot;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use PHPUnit\Framework\TestCase;

class PullGapPlannerTest extends TestCase
{
    private PullGapPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new PullGapPlanner();
    }

    public function testNoDeclarationsGivesEmptyPlan(): void
    {
        $plan = $this->planner->plan([
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recorded(2, '2025-01-01 11:00:00'),
            self::recordedS(3, '2025-01-01 12:00:00'),
            self::recorded(4, '2025-01-01 13:00:00'),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->warnings);
        $this->assertTrue($this->planner->plan([])->isEmpty());
    }

    public function testFillersGoOneSecondBeforeTheHistoryStart(): void
    {
        $plan = $this->planner->plan([
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recorded(2, '2025-01-01 11:00:00'),
            self::recordedS(3, '2025-01-01 12:00:00', declaredPity: 10),
        ]);

        $this->assertSame([], $plan->merges);
        $this->assertSame([], $plan->fillerMoves);
        $this->assertSame([], $plan->fillerDeletes);
        $this->assertSame(array_fill(0, 7, '2025-01-01 09:59:59'), self::slotDates($plan->fillerCreates));
        foreach ($plan->fillerCreates as $slot) {
            $this->assertSame(3, $slot->sId);
        }
    }

    public function testFillersAreSpreadStrictlyInsideABoundedGap(): void
    {
        $plan = $this->planner->plan([
            self::recordedS(1, '2025-01-01 00:00:00'),
            self::recorded(2, '2025-01-01 00:00:10'),
            self::recordedS(3, '2025-01-01 00:00:20', declaredPity: 5),
        ]);

        // Two gaps of 10 s: the earliest wins; 3 fillers at floor(k * 10 / 4)
        $this->assertSame(
            ['2025-01-01 00:00:02', '2025-01-01 00:00:05', '2025-01-01 00:00:07'],
            self::slotDates($plan->fillerCreates),
        );
    }

    public function testFillersShareSecondsWhenTheGapIsNarrow(): void
    {
        $plan = $this->planner->plan([
            self::recordedS(1, '2025-01-01 00:00:00'),
            self::recordedS(2, '2025-01-01 00:00:03', declaredPity: 6),
        ]);

        // Span 3 s for 5 fillers: only 00:00:01 and 00:00:02 are strictly inside
        $this->assertSame(
            ['2025-01-01 00:00:01', '2025-01-01 00:00:01', '2025-01-01 00:00:01', '2025-01-01 00:00:02', '2025-01-01 00:00:02'],
            self::slotDates($plan->fillerCreates),
        );
    }

    public function testFillersGoInTheWidestGap(): void
    {
        $plan = $this->planner->plan([
            self::recordedS(1, '2025-01-01 00:00:00'),
            self::recorded(2, '2025-01-01 00:01:00'),
            self::recorded(3, '2025-01-01 01:00:00'),
            self::recordedS(4, '2025-01-01 01:00:30', declaredPity: 6),
        ]);

        // Gaps: 60 s, 3540 s, 30 s; 3 fillers at 885 s, 1770 s, 2655 s after 00:01:00
        $this->assertSame(
            ['2025-01-01 00:15:45', '2025-01-01 00:30:30', '2025-01-01 00:45:15'],
            self::slotDates($plan->fillerCreates),
        );
        $this->assertSame([4, 4, 4], array_map(static fn (FillerSlot $slot): int => $slot->sId, $plan->fillerCreates));
    }

    public function testWindowWithoutRoomGivesAWarningAndNoFiller(): void
    {
        $plan = $this->planner->plan([
            self::recordedS(1, '2025-01-01 12:00:00'),
            self::recorded(2, '2025-01-01 12:00:00'),
            self::recorded(3, '2025-01-01 12:00:01'),
            self::recordedS(4, '2025-01-01 12:00:01', declaredPity: 10),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('S #4', $plan->warnings[0]);
    }

    public function testExistingFillersAreReusedMovedAndCompleted(): void
    {
        $plan = $this->planner->plan([
            self::filler(11, '2025-01-01 09:00:00'),
            self::filler(10, '2025-01-01 09:59:59'),
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00', declaredPity: 5),
        ]);

        // 3 fillers needed at 09:59:59: #11 moves, #10 is already there, one is created
        $this->assertSame([11], array_keys($plan->fillerMoves));
        $this->assertSame('2025-01-01 09:59:59', $plan->fillerMoves[11]->format('Y-m-d H:i:s'));
        $this->assertSame([], $plan->fillerDeletes);
        $this->assertSame(['2025-01-01 09:59:59'], self::slotDates($plan->fillerCreates));
    }

    public function testExcessFillersAreDeleted(): void
    {
        $plan = $this->planner->plan([
            self::filler(10, '2025-01-01 09:59:59'),
            self::filler(11, '2025-01-01 09:59:59'),
            self::filler(12, '2025-01-01 09:59:59'),
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00', declaredPity: 3),
        ]);

        // One filler needed: the first by (date, id) is reused, the others are deleted
        $this->assertSame([], $plan->fillerMoves);
        $this->assertSame([11, 12], $plan->fillerDeletes);
        $this->assertSame([], $plan->fillerCreates);
    }

    public function testFillersOfARemovedDeclarationAreDeleted(): void
    {
        $plan = $this->planner->plan([
            self::filler(10, '2025-01-01 09:59:59'),
            self::filler(11, '2025-01-01 09:59:59'),
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00'),
            self::filler(12, '2025-01-01 11:30:00'),
            self::recorded(3, '2025-01-01 12:00:00'),
        ]);

        $this->assertSame([10, 11, 12], $plan->fillerDeletes);
        $this->assertSame([], $plan->fillerMoves);
        $this->assertSame([], $plan->fillerCreates);
    }

    public function testManualSMergesOnlyIntoARecordedSInsertedAfterIt(): void
    {
        $entries = [
            self::manualS(20, '2024-12-31 23:50:00', 81, name: 'Neuvillette'),
            self::recordedS(10, '2024-12-31 23:55:00', name: 'Neuvillette'),
            self::recorded(11, '2025-01-01 10:00:00'),
        ];

        $this->assertSame([], $this->planner->plan($entries)->merges);

        $entries[1] = self::recordedS(30, '2024-12-31 23:55:00', name: 'neuvillette');
        $plan = $this->planner->plan($entries);

        $this->assertSame([20 => 30], $plan->merges);
        $this->assertSame([30 => 81], $plan->declaredPityUpdates);
    }

    public function testMergeMatchesByItemIdBeforeNameAndWithin24Hours(): void
    {
        $plan = $this->planner->plan([
            self::manualS(20, '2025-01-01 00:00:00', 70, itemId: '1046', name: 'Furina'),
            self::recordedS(30, '2025-01-01 00:10:00', itemId: '1047', name: 'Furina'),
            self::recordedS(32, '2025-01-02 00:00:00', itemId: '1046', name: 'Other name'),
            self::recordedS(31, '2025-01-02 00:00:01', itemId: '1046', name: 'Other name'),
        ]);

        // #30 has another item id, #31 is 1 s beyond 24 h; #32 is exactly 24 h away
        $this->assertSame([20 => 32], $plan->merges);
    }

    public function testMergePicksTheNearestCandidateOneToOne(): void
    {
        $plan = $this->planner->plan([
            self::manualS(20, '2025-01-01 10:00:00', 50, name: 'Furina'),
            self::recordedS(30, '2025-01-01 10:05:00', name: 'Furina'),
            self::manualS(21, '2025-01-01 10:06:00', 60, name: 'Furina'),
            self::recordedS(31, '2025-01-01 10:40:00', name: 'Furina'),
        ]);

        // #21 is nearest to #30 too, but #30 is already taken by #20
        $this->assertSame([20 => 30, 21 => 31], $plan->merges);
        $this->assertSame([30 => 50, 31 => 60], $plan->declaredPityUpdates);
    }

    public function testExistingCopyWithLowerIdIsNeverMerged(): void
    {
        // A second copy (constellation) added while the recorded one already existed
        $plan = $this->planner->plan([
            self::recorded(1, '2025-01-01 09:00:00'),
            self::recordedS(5, '2025-01-01 10:00:00', name: 'Furina'),
            self::manualS(9, '2025-01-01 10:03:00', 4, name: 'Furina'),
            self::recorded(6, '2025-01-01 11:00:00'),
        ]);

        $this->assertSame([], $plan->merges);
        $this->assertSame(['2025-01-01 10:00:45', '2025-01-01 10:01:30', '2025-01-01 10:02:15'], self::slotDates($plan->fillerCreates));
    }

    public function testDeclaredPityIsTransferredToTheRecordedS(): void
    {
        $entries = [
            self::manualS(20, '2025-01-01 09:00:00', 10, itemId: '1070'),
            self::recorded(31, '2025-01-01 09:10:00'),
            self::recordedS(32, '2025-01-01 09:20:00', itemId: '1070'),
            self::recorded(33, '2025-01-01 12:00:00'),
        ];

        $plan = $this->planner->plan($entries);

        $this->assertSame([20 => 32], $plan->merges);
        $this->assertSame([32 => 10], $plan->declaredPityUpdates);
        // Window of #32: 2 recorded pulls, 8 fillers before the first one (no previous S)
        $this->assertSame(array_fill(0, 8, '2025-01-01 09:09:59'), self::slotDates($plan->fillerCreates));
        $this->assertSame(32, $plan->fillerCreates[0]->sId);

        $this->assertTrue($this->planner->plan(self::apply($entries, $plan))->isEmpty());
    }

    public function testRecordedSAlreadyClaimedIsNotMergedAgain(): void
    {
        $entries = [
            self::manualS(20, '2025-01-01 10:00:00', 50, name: 'Furina'),
            self::manualS(21, '2025-01-01 10:20:00', 60, name: 'Furina'),
            self::recordedS(30, '2025-01-01 10:05:00', name: 'Furina'),
        ];
        usort($entries, static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => $a->pulledAt <=> $b->pulledAt);

        $plan = $this->planner->plan($entries);
        $this->assertSame([20 => 30], $plan->merges);

        // #30 now carries the declared pity of #20: the other manual copy must stay
        $applied = self::apply($entries, $plan);
        $this->assertTrue($this->planner->plan($applied)->isEmpty());
        $this->assertContains(21, array_map(static fn (PullTimelineEntry $entry): int => $entry->id, $applied));
    }

    public function testNonCountedEntriesAreBoundariesButNotPulls(): void
    {
        $plan = $this->planner->plan([
            self::recordedS(1, '2025-01-01 00:00:00'),
            self::recorded(2, '2025-01-01 00:10:00', countsAsPull: false),
            self::recorded(3, '2025-01-01 00:10:10'),
            self::recordedS(4, '2025-01-01 00:10:20', declaredPity: 5),
        ]);

        // Recorded pity 2 (the gift is not a pull), but the gift splits the 610 s gap
        $this->assertSame(
            ['2025-01-01 00:02:30', '2025-01-01 00:05:00', '2025-01-01 00:07:30'],
            self::slotDates($plan->fillerCreates),
        );
    }

    public function testTimezoneOfTheBoundsIsKept(): void
    {
        // Wall-clock values stored as UTC must come back as UTC, whatever the default timezone
        $utc = $this->planner->plan([
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00', declaredPity: 3),
        ]);
        $this->assertCount(1, $utc->fillerCreates);
        $this->assertSame('UTC', $utc->fillerCreates[0]->pulledAt->getTimezone()->getName());
        $this->assertSame('2025-01-01 09:59:59', $utc->fillerCreates[0]->pulledAt->format('Y-m-d H:i:s'));

        $shanghai = $this->planner->plan([
            self::recordedS(1, '2025-01-01 10:00:00', tz: 'Asia/Shanghai'),
            self::recorded(2, '2025-01-01 10:00:10', tz: 'Asia/Shanghai'),
            self::recordedS(3, '2025-01-01 14:00:00', declaredPity: 3, tz: 'Asia/Shanghai'),
        ]);
        $this->assertCount(1, $shanghai->fillerCreates);
        $this->assertSame('Asia/Shanghai', $shanghai->fillerCreates[0]->pulledAt->getTimezone()->getName());
        $this->assertSame('2025-01-01 12:00:05', $shanghai->fillerCreates[0]->pulledAt->format('Y-m-d H:i:s'));
    }

    public function testPlanIsIdempotentOnACombinedScenario(): void
    {
        $entries = [
            // Stale fillers: one before everything, one in the open window
            self::filler(90, '2024-06-01 00:00:00'),
            self::manualS(40, '2024-12-31 23:50:00', 81, itemId: '1070', name: 'Neuvillette'),
            self::recorded(50, '2024-12-31 23:55:00'),
            self::recordedS(51, '2024-12-31 23:59:58', itemId: '1070', name: 'Neuvillette'),
            self::recorded(1, '2025-01-01 00:00:00'),
            self::recorded(2, '2025-01-01 00:00:00'),
            self::recorded(3, '2025-01-01 00:00:03'),
            self::recordedS(4, '2025-01-01 00:00:03', declaredPity: 74),
            self::recorded(5, '2025-01-02 00:00:00'),
            self::recordedS(6, '2025-01-05 00:00:00'),
            self::filler(91, '2025-01-06 00:00:00'),
            self::recorded(7, '2025-01-07 00:00:00'),
        ];

        $plan = $this->planner->plan($entries);
        $this->assertSame([40 => 51], $plan->merges);
        $this->assertSame([51 => 81], $plan->declaredPityUpdates);
        // 79 fillers before #50 and 70 for #4 squeezed in its widest gap (3 s): the 2 stale ones move
        $this->assertCount(2, $plan->fillerMoves);
        $this->assertCount(147, $plan->fillerCreates);
        $this->assertSame([], $plan->fillerDeletes);
        $forS4 = array_values(array_filter($plan->fillerCreates, static fn (FillerSlot $slot): bool => $slot->sId === 4));
        $this->assertSame(['2025-01-01 00:00:01', '2025-01-01 00:00:02'], array_values(array_unique(self::slotDates($forS4))));

        $applied = self::apply($entries, $plan);
        $second = $this->planner->plan($applied);
        $this->assertTrue($second->isEmpty(), (string) json_encode($second));

        $description = $this->planner->describe($applied, self::date('2025-02-01 00:00:00'));
        $this->assertSame(81, $description['windows'][51]->declaredPity);
        $this->assertSame(2, $description['windows'][51]->recordedPity);
    }

    public function testPlanIsIdempotentOnRandomTimelines(): void
    {
        $guard = new ManualPullGuard($this->planner);
        $now = self::date('2030-01-01 00:00:00');
        $suggestions = 0;

        mt_srand(20261121);
        for ($run = 0; $run < 300; ++$run) {
            $entries = self::randomTimeline();
            $plan = $this->planner->plan($entries);
            $applied = self::apply($entries, $plan);
            $second = $this->planner->plan($applied);

            $this->assertTrue($second->isEmpty(), \sprintf('Run %d is not idempotent: %s', $run, json_encode($second)));
            $this->assertSame($plan->warnings, $second->warnings, \sprintf('Run %d changed its warnings', $run));

            // Every satisfiable declaration now adds up
            $description = $this->planner->describe($applied, $now);
            foreach ($description['windows'] as $sId => $window) {
                $fillers = \count(array_filter($applied, static fn (PullTimelineEntry $e): bool => $e->origin === PullOrigin::Filler && self::ownerOf($applied, $e) === $sId));
                if ($window->declaredPity !== null && $window->hasRoom) {
                    $this->assertSame(max($window->declaredPity, $window->recordedPity), $window->recordedPity + $fillers, \sprintf('Run %d, S #%d', $run, $sId));
                } else {
                    $this->assertSame(0, $fillers, \sprintf('Run %d, S #%d', $run, $sId));
                }
            }

            // The guard accepts every suggested date (pity 1 needs no filler room)
            foreach ($description['windows'] + ['open' => $description['open']] as $anchorId => $window) {
                if ($window->suggestedAt !== null) {
                    $guard->assertCanCreate($applied, \is_int($anchorId) ? $anchorId : null, $window->suggestedAt, 1, 90, null, 'Unlisted', true, $now);
                    ++$suggestions;
                }
            }
        }

        $this->assertGreaterThan(0, $suggestions);
    }

    public function testDescribeWindows(): void
    {
        $entries = [
            self::filler(90, '2025-01-01 09:59:59'),
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00', declaredPity: 5),
            self::manualS(30, '2025-01-01 11:30:00', 40),
            self::recorded(3, '2025-01-01 12:00:00'),
            self::recorded(4, '2025-01-01 12:00:00'),
            self::recordedS(5, '2025-01-01 12:00:01'),
            self::recorded(6, '2025-01-01 13:00:00', countsAsPull: false),
            self::recorded(7, '2025-01-01 14:00:00'),
        ];

        $description = $this->planner->describe($entries, new \DateTimeImmutable('2025-01-02 00:00:00', new \DateTimeZone('UTC')));
        $windows = $description['windows'];

        $this->assertSame([2, 30, 5], array_keys($windows));

        $this->assertSame([
            'recorded_pity' => 2,
            'declared_pity' => 5,
            'insert_after' => null,
            'insert_before' => '2025-01-01 10:00:00',
            'suggested_at' => '2025-01-01 09:59:00',
            'has_room' => true,
        ], $windows[2]->toArray());
        $this->assertSame(2, $windows[2]->sId);

        // A manual S alone in its window: the next pull is the S itself
        $this->assertSame([
            'recorded_pity' => 1,
            'declared_pity' => 40,
            'insert_after' => '2025-01-01 11:00:00',
            'insert_before' => '2025-01-01 11:30:00',
            'suggested_at' => '2025-01-01 11:29:00',
            'has_room' => true,
        ], $windows[30]->toArray());

        $this->assertSame([
            'recorded_pity' => 3,
            'declared_pity' => null,
            'insert_after' => '2025-01-01 11:30:00',
            'insert_before' => '2025-01-01 12:00:00',
            'suggested_at' => '2025-01-01 11:59:00',
            'has_room' => true,
        ], $windows[5]->toArray());

        $open = $description['open'];
        $this->assertNull($open->sId);
        $this->assertSame([
            'recorded_pity' => 1,
            'declared_pity' => null,
            'insert_after' => '2025-01-01 12:00:01',
            'insert_before' => '2025-01-01 13:00:00',
            'suggested_at' => '2025-01-01 12:59:00',
            'has_room' => true,
        ], $open->toArray());
    }

    public function testDescribeRoomRules(): void
    {
        $now = new \DateTimeImmutable('2025-02-01 00:00:00', new \DateTimeZone('UTC'));

        $tight = $this->planner->describe([
            self::recordedS(1, '2025-01-01 12:00:00'),
            self::recorded(2, '2025-01-01 12:00:01'),
            self::recordedS(3, '2025-01-01 12:00:01'),
        ], $now);
        $this->assertFalse($tight['windows'][3]->hasRoom);
        $this->assertNull($tight['windows'][3]->suggestedAt);
        $this->assertTrue($tight['windows'][1]->hasRoom);

        // Open window: no S at all, then nothing after the last S
        $noS = $this->planner->describe([self::recorded(1, '2025-01-01 12:00:00')], $now);
        $this->assertSame([], $noS['windows']);
        $this->assertTrue($noS['open']->hasRoom);
        $this->assertNull($noS['open']->insertAfter);
        $this->assertSame('2025-01-01 11:59:00', $noS['open']->suggestedAt?->format('Y-m-d H:i:s'));

        $endsWithS = $this->planner->describe([self::recordedS(1, '2025-01-01 12:00:00')], $now);
        $this->assertFalse($endsWithS['open']->hasRoom);
        $this->assertSame(0, $endsWithS['open']->recordedPity);
        $this->assertNull($endsWithS['open']->insertBefore);
    }

    public function testSuggestedDateRules(): void
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2025-01-10 00:00:00.750000', $utc);
        $suggest = fn (array $entries, ?\DateTimeImmutable $at = null): ?string => $this->planner
            ->describe($entries, $at ?? $now)['open']->suggestedAt?->format('Y-m-d H:i:s.u');

        // Wide interval: one minute before the next pull
        $this->assertSame('2025-01-01 11:59:00.000000', $suggest([self::recordedS(1, '2025-01-01 10:00:00'), self::recorded(2, '2025-01-01 12:00:00')]));
        // Narrower than 2 minutes: the midpoint, floored
        $this->assertSame('2025-01-01 10:00:49.000000', $suggest([self::recordedS(1, '2025-01-01 10:00:00'), self::recorded(2, '2025-01-01 10:01:39')]));
        $this->assertSame('2025-01-01 10:00:01.000000', $suggest([self::recordedS(1, '2025-01-01 10:00:00'), self::recorded(2, '2025-01-01 10:00:02')]));
        // No second strictly inside
        $this->assertNull($suggest([self::recordedS(1, '2025-01-01 10:00:00'), self::recorded(2, '2025-01-01 10:00:01')]));
        // No next pull: one day after the previous S, or now when sooner (floored)
        $this->assertSame('2025-01-02 10:00:00.000000', $suggest([self::recordedS(1, '2025-01-01 10:00:00')]));
        $this->assertSame('2025-01-10 00:00:00.000000', $suggest([self::recordedS(1, '2025-01-09 12:00:00')]));
        // Empty banner: now
        $this->assertSame('2025-01-10 00:00:00.000000', $suggest([]));
        // Never after now, even when the next pull is later
        $this->assertSame('2025-01-10 00:00:00.000000', $suggest([self::recordedS(1, '2025-01-09 00:00:00'), self::recorded(2, '2025-01-10 00:01:30')]));
        // Previous S in the future of now: nothing valid
        $this->assertNull($suggest([self::recordedS(1, '2025-01-11 00:00:00')]));

        // The timezone of the bound is kept
        $suggested = $this->planner->describe([self::recordedS(1, '2025-01-01 10:00:00')], $now)['open']->suggestedAt;
        $this->assertSame('UTC', $suggested?->getTimezone()->getName());
    }

    public function testNormalizeName(): void
    {
        $this->assertSame('hutao', PullGapPlanner::normalizeName('Hu Tao'));
        $this->assertSame('thecatch', PullGapPlanner::normalizeName('"The Catch"'));
        $this->assertSame('march7th', PullGapPlanner::normalizeName('March 7th'));
        $this->assertSame('', PullGapPlanner::normalizeName(null));

        $this->assertTrue(PullGapPlanner::isSameItem(null, 'Hu Tao', '', 'hu-tao'));
        $this->assertFalse(PullGapPlanner::isSameItem('1', 'Hu Tao', '2', 'Hu Tao'));
        $this->assertTrue(PullGapPlanner::isSameItem('1', 'A', '1', 'B'));
        // No ASCII letter: never a match by name
        $this->assertFalse(PullGapPlanner::isSameItem(null, '胡桃', null, '胡桃'));
    }

    /**
     * Applies a plan like a provider does: drops the merged manual rows, sets the declared
     * pities, moves and deletes fillers, creates the new ones with fresh ids, then sorts by
     * date (stable, so rows sharing a second keep their order).
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
            );
        }
        foreach ($plan->fillerCreates as $slot) {
            $result[] = new PullTimelineEntry($nextId++, $slot->pulledAt, PullOrigin::Filler, false, true);
        }

        usort($result, static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => $a->pulledAt->getTimestamp() <=> $b->pulledAt->getTimestamp());

        return $result;
    }

    /**
     * The S whose window contains a filler (the first S at or after it).
     *
     * @param list<PullTimelineEntry> $entries
     */
    private static function ownerOf(array $entries, PullTimelineEntry $filler): ?int
    {
        $seen = false;
        foreach ($entries as $entry) {
            $seen = $seen || $entry === $filler;
            if ($seen && $entry->isS && $entry->origin !== PullOrigin::Filler) {
                return $entry->id;
            }
        }

        return null;
    }

    /**
     * @return list<PullTimelineEntry>
     */
    private static function randomTimeline(): array
    {
        $time = mt_rand(1_700_000_000, 1_750_000_000);
        $names = ['Furina', 'Neuvillette', 'Raiden'];
        $entries = [];
        $nextId = 1;
        $count = mt_rand(0, 40);
        for ($i = 0; $i < $count; ++$i) {
            // Gaps from 0 s (same ten-pull) to several days
            $time += [0, 0, 1, 2, 3, 60, 3600, 86400 * 3][mt_rand(0, 7)];
            $at = (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('UTC'));
            $roll = mt_rand(1, 100);
            if ($roll <= 15) {
                $entries[] = new PullTimelineEntry($nextId++, $at, PullOrigin::Recorded, true, true, mt_rand(0, 2) === 0 ? mt_rand(1, 90) : null, null, $names[mt_rand(0, 2)]);
            } elseif ($roll <= 22) {
                $entries[] = new PullTimelineEntry(1000 + $nextId++, $at, PullOrigin::Manual, true, true, mt_rand(1, 90), null, $names[mt_rand(0, 2)]);
            } elseif ($roll <= 30) {
                $entries[] = new PullTimelineEntry(5000 + $nextId++, $at->setTimestamp($time + mt_rand(-200000, 200000)), PullOrigin::Filler, false, true);
            } else {
                $entries[] = new PullTimelineEntry($nextId++, $at, PullOrigin::Recorded, false, mt_rand(1, 10) > 1);
            }
        }

        // Recorded rows of a later import get higher ids than the manual rows
        foreach ($entries as $index => $entry) {
            if ($entry->origin === PullOrigin::Recorded && mt_rand(0, 3) === 0) {
                $entries[$index] = new PullTimelineEntry(2000 + $entry->id, $entry->pulledAt, $entry->origin, $entry->isS, $entry->countsAsPull, $entry->declaredPity, $entry->itemId, $entry->name);
            }
        }

        usort($entries, static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => $a->pulledAt->getTimestamp() <=> $b->pulledAt->getTimestamp());

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

    private static function date(string $date, string $tz = 'UTC'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone($tz));
    }

    private static function recorded(int $id, string $at, bool $countsAsPull = true, string $tz = 'UTC'): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at, $tz), PullOrigin::Recorded, false, $countsAsPull);
    }

    private static function recordedS(int $id, string $at, ?int $declaredPity = null, ?string $itemId = null, ?string $name = null, string $tz = 'UTC'): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at, $tz), PullOrigin::Recorded, true, true, $declaredPity, $itemId, $name);
    }

    private static function manualS(int $id, string $at, int $declaredPity, ?string $itemId = null, ?string $name = null): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Manual, true, true, $declaredPity, $itemId, $name);
    }

    private static function filler(int $id, string $at): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Filler, false, true);
    }
}
