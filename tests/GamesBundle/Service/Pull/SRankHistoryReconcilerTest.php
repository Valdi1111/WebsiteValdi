<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBanner;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBannerPlan;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRowUpdate;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\Pull\SRankHistoryReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Chain checks, ordered alignment, target values and the in-memory simulation of the S-rank history import, on
 * made-up banners. Every accepted plan has already passed the simulation: each record lands on its pull number.
 */
class SRankHistoryReconcilerTest extends TestCase
{
    private const int S_MAX_PITY = 90;

    private SRankHistoryReconciler $reconciler;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->reconciler = new SRankHistoryReconciler(new PullGapPlanner());
    }

    private static function at(string $wallClock): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock, new \DateTimeZone('UTC'));
    }

    private static function record(int $pullNumber, int $pity, string $at, string $itemId, string $name, ?bool $isUp = true): SRankHistoryRecord
    {
        return new SRankHistoryRecord($itemId, $name, 'Agents', $pullNumber, $pity, self::at($at), $isUp);
    }

    private function s(string $at, string $itemId, string $name, PullOrigin $origin = PullOrigin::Recorded, ?int $declaredPity = null, ?int $pullNumber = null): PullTimelineEntry
    {
        return new PullTimelineEntry($this->nextId++, self::at($at), $origin, true, true, $declaredPity, $itemId, $name, $pullNumber);
    }

    private function b(string $at): PullTimelineEntry
    {
        return new PullTimelineEntry($this->nextId++, self::at($at), PullOrigin::Recorded, false, true, null, '9001', 'Some B');
    }

    private function filler(string $at, ?int $pullNumber = null): PullTimelineEntry
    {
        return new PullTimelineEntry($this->nextId++, self::at($at), PullOrigin::Filler, false, true, pullNumber: $pullNumber);
    }

    /**
     * Recorded B pulls one minute apart, the first at $start.
     *
     * @return list<PullTimelineEntry>
     */
    private function bPulls(int $count, string $start): array
    {
        $entries = [];
        for ($k = 0; $k < $count; ++$k) {
            $entries[] = $this->b(self::at($start)->modify(\sprintf('+%d minutes', $k))->format('Y-m-d H:i:s'));
        }

        return $entries;
    }

    /**
     * @param list<SRankHistoryRecord> $records
     * @param list<PullTimelineEntry>  $entries
     */
    private function reconcile(array $records, array $entries, ?int $totalPulls = null, ?int $sCount = null): SRankHistoryBannerPlan
    {
        return $this->reconciler->reconcile(new SRankHistoryBanner('2', $records, $totalPulls, $sCount), $entries, self::S_MAX_PITY);
    }

    /**
     * @return array<int, SRankHistoryRowUpdate> By row id
     */
    private static function updatesById(SRankHistoryBannerPlan $plan): array
    {
        $byId = [];
        foreach ($plan->updates as $update) {
            $byId[$update->id] = $update;
        }

        return $byId;
    }

    public function testCompleteHistoryOnlyConfirmsTheStoredS(): void
    {
        // Pulls 1-30: S at 10 and 30
        $entries = [...$this->bPulls(9, '2025-01-01 10:00:00'), $alpha = $this->s('2025-01-01 10:09:00', '1001', 'Alpha'),
            ...$this->bPulls(19, '2025-01-02 10:00:00'), $beta = $this->s('2025-01-02 10:19:00', '1002', 'Beta')];

        $plan = $this->reconcile([
            self::record(30, 20, '2025-01-02 10:19:00', '1002', 'Beta', true),
            self::record(10, 10, '2025-01-01 10:09:00', '1001', 'Alpha', false),
        ], $entries, totalPulls: 30, sCount: 2);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->creates);
        $this->assertSame([], $plan->overwrites);
        $this->assertSame([], $plan->warnings);
        $this->assertSame([10, 30], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->records), 'By pull number');
        $updates = self::updatesById($plan);
        $this->assertSame([$alpha->id, $beta->id], array_keys($updates));
        $this->assertFalse($updates[$alpha->id]->official, 'A recorded S stays recorded');
        $this->assertNull($updates[$alpha->id]->declaredPity, 'Nothing is missing before it');
        $this->assertFalse($updates[$alpha->id]->record->isUp);
        $this->assertTrue($updates[$beta->id]->record->isUp);
    }

    public function testSOlderThanTheStoredHistoryBecomeOfficialRows(): void
    {
        // The stored history starts at pull 100 (a 6-month window): pulls 40 and 95 are missing, 130 is stored
        $entries = [...$this->bPulls(30, '2025-03-01 10:00:00'), $gamma = $this->s('2025-03-01 10:30:00', '1003', 'Gamma'),
            ...$this->bPulls(5, '2025-03-02 10:00:00')];

        $plan = $this->reconcile([
            self::record(40, 40, '2024-08-01 12:00:00', '1001', 'Alpha'),
            self::record(95, 55, '2024-11-01 12:00:00', '1002', 'Beta', false),
            self::record(130, 35, '2025-03-01 10:30:00', '1003', 'Gamma'),
        ], $entries, totalPulls: 135);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->warnings, 'Totals match once the 99 missing pulls are counted');
        $this->assertSame([40, 95], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
        $update = self::updatesById($plan)[$gamma->id];
        $this->assertFalse($update->official);
        $this->assertSame(35, $update->declaredPity, '4 pulls are still missing before it (96 to 99)');
    }

    public function testAnSMissingInTheMiddleSplitsARecordedWindow(): void
    {
        // An old backup (pulls 1-30), nothing for pulls 31-60, then the API window (pulls 61-100)
        $entries = [...$this->bPulls(19, '2024-06-01 10:00:00'), $alpha = $this->s('2024-06-01 10:19:00', '1001', 'Alpha'),
            ...$this->bPulls(10, '2024-06-02 10:00:00'),
            ...$this->bPulls(19, '2024-09-01 10:00:00'), $gamma = $this->s('2024-09-01 10:19:00', '1003', 'Gamma'),
            ...$this->bPulls(20, '2024-09-02 10:00:00')];

        $plan = $this->reconcile([
            self::record(20, 20, '2024-06-01 10:19:00', '1001', 'Alpha'),
            self::record(45, 25, '2024-07-15 18:00:00', '1002', 'Beta'),
            self::record(80, 35, '2024-09-01 10:19:00', '1003', 'Gamma'),
        ], $entries, totalPulls: 100, sCount: 3);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->warnings);
        $this->assertSame([45], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
        $updates = self::updatesById($plan);
        $this->assertNull($updates[$alpha->id]->declaredPity);
        $this->assertSame(35, $updates[$gamma->id]->declaredPity, '20 stored pulls up to Gamma, 15 missing after Beta');
    }

    public function testTwoSOfOneTenPullAreBothCreatedInTheirSecond(): void
    {
        $entries = $this->bPulls(5, '2025-05-01 10:00:00');

        $plan = $this->reconcile([
            self::record(10, 10, '2025-01-10 20:00:00', '1001', 'Alpha'),
            self::record(50, 40, '2025-02-10 20:00:00', '1002', 'Beta'),
            self::record(54, 4, '2025-02-10 20:00:00', '1003', 'Gamma'),
        ], $entries, totalPulls: 59);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->warnings);
        $this->assertSame([10, 50, 54], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
    }

    public function testAStoredSOneHourOffIsMatchedAndKeepsItsDate(): void
    {
        $entries = [...$this->bPulls(49, '2024-07-01 10:00:00'), $alpha = $this->s('2024-07-01 12:00:00', '1001', 'Alpha')];

        $plan = $this->reconcile([self::record(50, 50, '2024-07-01 11:00:00', '1001', 'Alpha')], $entries);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->creates);
        $this->assertSame([$alpha->id], array_keys(self::updatesById($plan)));
        $this->assertCount(1, $plan->warnings);
        $this->assertStringContainsString('(+3600 s): the stored date is kept', $plan->warnings[0]);
    }

    public function testTheClosestStoredCopyIsMatched(): void
    {
        // Two copies of Alpha two hours apart, the first one missing from the stored history: both records are within 24 h
        // of the stored copy
        $entries = [...$this->bPulls(8, '2025-01-01 10:00:00'), $second = $this->s('2025-01-01 11:00:00', '1001', 'Alpha')];

        $plan = $this->reconcile([
            self::record(1, 1, '2025-01-01 09:00:00', '1001', 'Alpha'),
            self::record(10, 9, '2025-01-01 11:00:00', '1001', 'Alpha'),
        ], $entries);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([1], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
        $this->assertSame(10, self::updatesById($plan)[$second->id]->record->pullNumber, 'The copy of 11:00, not the first record within 24 h');
    }

    public function testARecordedCopyMoreThanADayAwayIsAnotherPull(): void
    {
        // The stored history starts on 4 January; its Alpha of 5 January is newer than the official history
        $entries = [...$this->bPulls(9, '2025-01-04 10:00:00'), $this->s('2025-01-05 10:00:00', '1001', 'Alpha')];

        $plan = $this->reconcile([self::record(10, 10, '2025-01-01 10:00:00', '1001', 'Alpha')], $entries);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([10], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
        $this->assertSame([], $plan->updates);
    }

    public function testAnOfficialRowIsMatchedByItsPullNumberOnly(): void
    {
        // An earlier import wrote pull 30; pull 10 (the same item) is not stored
        $entries = [$official = $this->s('2025-01-03 10:00:00', '1001', 'Alpha', PullOrigin::Official, 20, 30)];

        $plan = $this->reconcile([
            self::record(10, 10, '2025-01-01 10:00:00', '1001', 'Alpha'),
            self::record(30, 20, '2025-01-03 10:00:00', '1001', 'Alpha'),
        ], $entries);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([10], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $plan->creates));
        $this->assertSame(30, self::updatesById($plan)[$official->id]->record->pullNumber);
    }

    public function testAStoredSMissingFromTheHistoryIsAConflictUnlessItIsNewer(): void
    {
        $entries = [...$this->bPulls(9, '2025-01-01 10:00:00'), $this->s('2025-01-01 10:09:00', '1001', 'Alpha'),
            ...$this->bPulls(9, '2025-01-02 10:00:00'), $this->s('2025-01-02 10:09:00', '1099', 'Intruder'),
            ...$this->bPulls(9, '2025-01-03 10:00:00'), $this->s('2025-01-03 10:09:00', '1002', 'Beta'),
            ...$this->bPulls(4, '2025-01-04 10:00:00'), $this->s('2025-01-04 10:04:00', '1003', 'Newer')];

        $refused = $this->reconcile([
            self::record(10, 10, '2025-01-01 10:09:00', '1001', 'Alpha'),
            self::record(30, 20, '2025-01-03 10:09:00', '1002', 'Beta'),
        ], $entries);

        $this->assertTrue($refused->isRefused());
        $this->assertSame(['The recorded S "Intruder" of 2025-01-02 10:09:00 is not in the official history.'], $refused->conflicts, 'The S pulled after the history (Newer) is fine');
        $this->assertSame([], $refused->creates);
        $this->assertSame([], $refused->updates);
    }

    public function testAManualSOlderThanTheFirstRecordIsAConflict(): void
    {
        $entries = [$this->s('2024-01-01 10:00:00', '1099', 'Guess', PullOrigin::Manual, 30), ...$this->bPulls(9, '2025-01-01 10:00:00'), $this->s('2025-01-01 10:09:00', '1001', 'Alpha')];

        $plan = $this->reconcile([self::record(40, 40, '2025-01-01 10:09:00', '1001', 'Alpha')], $entries);

        $this->assertSame(['The manual S "Guess" of 2024-01-01 10:00:00 is not in the official history.'], $plan->conflicts);
    }

    public function testASecondMixingStoredPullsAndANewOfficialSIsAConflict(): void
    {
        // Only part of the 10-pull of 12:00 is stored, without its S
        $entries = [$this->b('2025-01-01 12:00:00'), $this->b('2025-01-01 12:00:00'), ...$this->bPulls(5, '2025-01-02 10:00:00')];

        $plan = $this->reconcile([self::record(5, 5, '2025-01-01 12:00:00', '1001', 'Alpha')], $entries);

        $this->assertSame(['The pulls of 2025-01-01 12:00:00 would mix rows with and without a pull number: their order would be unknown.'], $plan->conflicts);
        $this->assertSame([], $plan->creates);
    }

    public function testMoreStoredPullsThanTheOfficialPityIsAConflict(): void
    {
        $entries = [...$this->bPulls(20, '2025-01-01 10:00:00'), $this->s('2025-01-01 10:20:00', '1001', 'Alpha')];

        $plan = $this->reconcile([self::record(15, 15, '2025-01-01 10:20:00', '1001', 'Alpha')], $entries);

        $this->assertSame(['21 pulls are stored up to the S "Alpha" of 2025-01-01 10:20:00, but its official pity is 15.'], $plan->conflicts);
    }

    public function testNoRoomForTheMissingPullsIsAConflict(): void
    {
        // Pull 10 is missing and pull 15 is stored one second after it: no second left for pulls 11 to 14
        $entries = [$this->s('2025-01-01 10:00:01', '1002', 'Beta')];

        $plan = $this->reconcile([
            self::record(10, 10, '2025-01-01 10:00:00', '1001', 'Alpha'),
            self::record(15, 5, '2025-01-01 10:00:01', '1002', 'Beta'),
        ], $entries);

        $this->assertTrue($plan->isRefused());
        $this->assertStringStartsWith('The missing pulls do not fit: No room for 4 unknown pull(s)', $plan->conflicts[0]);
    }

    public function testAManualSBecomesOfficialAndTheReplacedUserValuesAreReported(): void
    {
        $entries = [$manual = $this->s('2024-12-20 10:00:00', '1001', 'Alpha', PullOrigin::Manual, 70),
            ...$this->bPulls(9, '2025-01-01 10:00:00'), $beta = $this->s('2025-01-01 10:09:00', '1002', 'Beta', declaredPity: 30)];

        $plan = $this->reconcile([
            self::record(40, 40, '2024-12-21 18:30:00', '1001', 'Alpha'),
            self::record(60, 20, '2025-01-01 10:09:00', '1002', 'Beta'),
        ], $entries);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->creates);
        $updates = self::updatesById($plan);
        $this->assertTrue($updates[$manual->id]->official);
        $this->assertSame(40, $updates[$manual->id]->declaredPity);
        $this->assertSame('2024-12-21 18:30:00', $updates[$manual->id]->record->pulledAt->format('Y-m-d H:i:s'));
        $this->assertFalse($updates[$beta->id]->official);
        $this->assertSame(20, $updates[$beta->id]->declaredPity, '10 stored pulls up to Beta, 10 missing');
        $this->assertSame([
            'Manual S "Alpha" (2024-12-20 10:00:00, pity 70) replaced by the official one (2024-12-21 18:30:00, pity 40).',
            'Declared pity of the S "Beta" of 2025-01-01 10:09:00: 30 replaced by the official pity 20.',
        ], $plan->overwrites);
    }

    public function testAUserDeclaredPityIsClearedWhenNothingIsMissing(): void
    {
        $entries = [...$this->bPulls(9, '2025-01-01 10:00:00'), $alpha = $this->s('2025-01-01 10:09:00', '1001', 'Alpha', declaredPity: 50)];

        $plan = $this->reconcile([self::record(10, 10, '2025-01-01 10:09:00', '1001', 'Alpha')], $entries);

        $this->assertNull(self::updatesById($plan)[$alpha->id]->declaredPity);
        $this->assertSame(['Declared pity of the S "Alpha" of 2025-01-01 10:09:00: 50 replaced by none (no pull is missing before it).'], $plan->overwrites);
    }

    public function testOfficialRowsOfAnEarlierImportAreMatchedByPullNumber(): void
    {
        // What the import of testTwoSOfOneTenPullAreBothCreatedInTheirSecond leaves, fillers included
        $entries = [];
        for ($k = 0; $k < 9; ++$k) {
            $entries[] = $this->filler('2025-01-10 19:59:59');
        }
        $entries[] = $alpha = $this->s('2025-01-10 20:00:00', '1001', 'Alpha', PullOrigin::Official, 10, 10);
        for ($k = 0; $k < 39; ++$k) {
            $entries[] = $this->filler('2025-01-25 08:00:00');
        }
        $entries[] = $beta = $this->s('2025-02-10 20:00:00', '1002', 'Beta', PullOrigin::Official, 40, 50);
        foreach ([51, 52, 53] as $number) {
            $entries[] = $this->filler('2025-02-10 20:00:00', $number);
        }
        $entries[] = $gamma = $this->s('2025-02-10 20:00:00', '1003', 'Gamma', PullOrigin::Official, 4, 54);
        array_push($entries, ...$this->bPulls(5, '2025-05-01 10:00:00'));

        $plan = $this->reconcile([
            self::record(10, 10, '2025-01-10 20:00:00', '1001', 'Alpha'),
            self::record(50, 40, '2025-02-10 20:00:00', '1002', 'Beta'),
            self::record(54, 4, '2025-02-10 20:00:00', '1003', 'Gamma'),
        ], $entries, totalPulls: 59);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([], $plan->creates);
        $this->assertSame([], $plan->overwrites);
        $updates = self::updatesById($plan);
        $this->assertSame([$alpha->id, $beta->id, $gamma->id], array_keys($updates));
        $this->assertSame([10, 40, 4], array_map(static fn (SRankHistoryRowUpdate $update): ?int => $update->declaredPity, array_values($updates)));
        $this->assertTrue($updates[$gamma->id]->official);
    }

    public function testChainChecks(): void
    {
        $alpha = self::record(10, 10, '2025-01-01 10:00:00', '1001', 'Alpha');
        $cases = [
            'Pull #10 is listed twice with different values ("Alpha", "Beta").' => [$alpha, self::record(10, 10, '2025-01-01 10:00:00', '1002', 'Beta')],
            'The pity of pull #30 ("Beta") is 25, but it is 20 pulls after the previous S.' => [$alpha, self::record(30, 25, '2025-01-02 10:00:00', '1002', 'Beta')],
            'The pity of pull #101 ("Beta") is 91, above the 90 of the banner.' => [$alpha, self::record(101, 91, '2025-01-02 10:00:00', '1002', 'Beta')],
            'Pull #20 is dated before pull #10.' => [$alpha, self::record(20, 10, '2024-12-31 10:00:00', '1002', 'Beta')],
            'Pulls #10 and #20 share the second 2025-01-01 10:00:00 but are 10 pulls apart: they cannot belong to one 10-pull.' => [$alpha, self::record(20, 10, '2025-01-01 10:00:00', '1002', 'Beta')],
            'The last S is pull #10, but the official history counts 9 pulls.' => [$alpha],
        ];
        foreach ($cases as $expected => $records) {
            $plan = $this->reconciler->reconcile(new SRankHistoryBanner('2', $records, $records === [$alpha] ? 9 : null), [], self::S_MAX_PITY);
            $this->assertContains($expected, $plan->conflicts);
            $this->assertSame([], $plan->creates);
        }

        // An exact duplicate is only a warning, nine pulls apart in one second is fine
        $plan = $this->reconcile([$alpha, $alpha, self::record(19, 9, '2025-01-01 10:00:00', '1002', 'Beta')], []);
        $this->assertSame([], $plan->conflicts);
        $this->assertSame(['Pull #10 is listed twice by the official history.'], $plan->warnings);
        $this->assertCount(2, $plan->creates);
    }

    public function testTotalsThatDifferAreOnlyWarnings(): void
    {
        $entries = [...$this->bPulls(9, '2025-01-01 10:00:00'), $this->s('2025-01-01 10:09:00', '1001', 'Alpha'), ...$this->bPulls(3, '2025-01-02 10:00:00')];

        $plan = $this->reconcile([self::record(10, 10, '2025-01-01 10:09:00', '1001', 'Alpha')], $entries, totalPulls: 12, sCount: 2);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([
            '13 pulls after the import, the official history counts 12.',
            'The official history lists 1 S, but counts 2.',
        ], $plan->warnings);
    }

    public function testFindPositionMismatches(): void
    {
        $entries = [$this->b('2025-01-01 10:00:00'), $this->s('2025-01-01 10:01:00', '1001', 'Alpha'), $this->filler('2025-01-01 10:02:00')];
        $records = [
            self::record(2, 2, '2025-01-01 10:01:00', '1001', 'Alpha'),
            self::record(1, 1, '2025-01-01 10:00:00', '1002', 'Beta'),
            self::record(3, 1, '2025-01-01 10:02:00', '1003', 'Gamma'),
            self::record(4, 1, '2025-01-01 10:03:00', '1004', 'Delta'),
        ];

        $this->assertSame([
            'Pull #1 should be the S "Beta" of 2025-01-01 10:00:00, but it is "Some B", not an S.',
            'Pull #3 should be the S "Gamma" of 2025-01-01 10:02:00, but it is an unknown pull.',
            'Pull #4 should be the S "Delta" of 2025-01-01 10:03:00, but it is missing (3 pulls).',
        ], $this->reconciler->findPositionMismatches($records, $entries));
    }
}
