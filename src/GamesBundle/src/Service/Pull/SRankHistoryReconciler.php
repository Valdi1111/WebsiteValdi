<?php

namespace App\GamesBundle\Service\Pull;

use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBanner;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBannerPlan;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRowUpdate;

/**
 * Compares the official S-rank history of ONE banner group with its stored rows and works out what an import must
 * write, without writing anything (provider-agnostic, pure).
 *
 * 1. Chain checks on the records alone: unique pull numbers, pity = distance from the previous S, pity within the
 *    banner's limit, dates in pull order, two S of one second at most MAX_PULLS_APART_IN_ONE_SECOND apart (the pulls of
 *    a 10-pull share its second), last pull number within the banner total.
 * 2. Ordered alignment of the records with the stored S (fillers ignored): same item, and for a recorded S a date within
 *    ManualPull::MERGE_WINDOW_SECONDS (dates are never compared to the second: a stored S may be off by an hour), for an
 *    official S the same pull number, for a manual S (an estimate) the item only. The alignment keeps the most pairs,
 *    then the closest dates. A stored S left out is a conflict, unless it is a recorded S newer than the whole official
 *    history (pulled since the history was last updated). A manual S is never exempted: its date is an estimate.
 * 3. Target values, "official data wins": unmatched records become official S rows (their pity as declared pity, their
 *    pull number, exact date and rate-up flag); a matched manual S becomes an official S the same way; a matched recorded
 *    S keeps its date and item, takes the rate-up flag, and the official pity as declared pity only when pulls are still
 *    missing before it (null otherwise). Every user value replaced is reported in $overwrites.
 * 4. In-memory simulation: the stored rows with these values, then PullGapPlanner::plan() applied. It must give no
 *    warning, every record must end up at its pull number, and the banner must not end up with more pulls than both the
 *    stored rows and the official total (stored rows pushed after an S, e.g. two sources one hour apart, would be
 *    replaced by made-up pulls), otherwise the banner is refused. More pulls than the official total alone is fine: pulls
 *    made since the history was last updated.
 *
 * Inside one second the simulation orders like the providers (PullTimelineEntry): unnumbered rows first, in their stored
 * order, then the numbered ones by number. A second never mixes numbered and unnumbered non-filler rows: that is a
 * conflict too.
 */
class SRankHistoryReconciler
{
    /** The pulls of one 10-pull share its second: two S of the same second are at most this many pulls apart */
    public const int MAX_PULLS_APART_IN_ONE_SECOND = 9;

    public function __construct(
        private readonly PullGapPlanner $planner,
    ) {
    }

    /**
     * @param list<PullTimelineEntry> $entries  Rows of the banner group in the provider's order, fillers included
     * @param int                     $sMaxPity Pity limit of the banner's S
     */
    public function reconcile(SRankHistoryBanner $banner, array $entries, int $sMaxPity): SRankHistoryBannerPlan
    {
        $warnings = [];
        $errors = [];
        $records = $this->checkChain($banner, $sMaxPity, $errors, $warnings);
        if ($errors !== [] || $records === []) {
            return new SRankHistoryBannerPlan(records: $records, conflicts: $errors, warnings: $warnings);
        }

        $stored = array_values(array_filter(
            $entries,
            static fn (PullTimelineEntry $entry): bool => $entry->origin !== PullOrigin::Filler && $entry->isS,
        ));
        $pairs = $this->align($records, $stored);

        // A stored S the official history does not list: only a recorded S after its last record is acceptable (pulled
        // since). The date of a manual S is an estimate: unpaired, it may stand for an S the history lists under another name
        $conflicts = [];
        $lastRecordAt = $records[array_key_last($records)]->pulledAt->getTimestamp();
        $lastPaired = $pairs === [] ? -1 : max($pairs);
        $paired = array_flip($pairs);
        foreach ($stored as $index => $entry) {
            if (isset($paired[$index])
                || ($entry->origin === PullOrigin::Recorded && $index > $lastPaired && $entry->pulledAt->getTimestamp() > $lastRecordAt)) {
                continue;
            }
            $conflicts[] = $entry->origin === PullOrigin::Manual
                ? \sprintf(
                    'The manual S "%s" of %s is not in the official history: delete it, or add it again with the right item, then import again.',
                    $entry->name ?? '?',
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                )
                : \sprintf(
                    'The %s S "%s" of %s is not in the official history.',
                    $entry->origin->value,
                    $entry->name ?? '?',
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                );
        }
        if ($conflicts !== []) {
            return new SRankHistoryBannerPlan(records: $records, conflicts: $conflicts, warnings: $warnings);
        }

        // Simulated rows: the stored ones (matched manual and official S as official S), then the new official S
        $byId = [];
        $nextId = 1;
        foreach ($entries as $entry) {
            $byId[$entry->id] = $entry;
            $nextId = max($nextId, $entry->id + 1);
        }
        /** @var array<int, int> $entryIdOfRecord record index => simulated entry id */
        $entryIdOfRecord = [];
        foreach ($pairs as $recordIndex => $storedIndex) {
            $entry = $stored[$storedIndex];
            $entryIdOfRecord[$recordIndex] = $entry->id;
            if ($entry->origin !== PullOrigin::Recorded) {
                $byId[$entry->id] = self::officialEntry($entry->id, $records[$recordIndex], $entry->countsAsPull);
            }
        }
        $creates = [];
        foreach ($records as $recordIndex => $record) {
            if (!isset($pairs[$recordIndex])) {
                $creates[] = $record;
                $entryIdOfRecord[$recordIndex] = $nextId;
                $byId[$nextId] = self::officialEntry($nextId, $record, true);
                ++$nextId;
            }
        }
        $seqs = array_flip(array_keys($byId));
        $simulated = self::sortEntries(array_values($byId), $seqs);

        foreach (self::findMixedSeconds($simulated) as $second) {
            $conflicts[] = \sprintf('The pulls of %s would mix rows with and without a pull number: their order would be unknown.', $second);
        }
        if ($conflicts !== []) {
            return new SRankHistoryBannerPlan(records: $records, conflicts: $conflicts, warnings: $warnings);
        }

        // Declared pities: from the pulls stored in each window once the new S split them
        $windows = $this->planner->describe($simulated, new \DateTimeImmutable())['windows'];
        $declaredPities = [];
        foreach ($records as $recordIndex => $record) {
            $entry = $byId[$entryIdOfRecord[$recordIndex]];
            $recorded = $windows[$entry->id]->recordedPity;
            if ($recorded > $record->pity) {
                $conflicts[] = \sprintf(
                    '%d pulls are stored up to the S "%s" of %s, but its official pity is %d.',
                    $recorded,
                    $record->name,
                    $record->pulledAt->format(ManualPull::DATE_FORMAT),
                    $record->pity,
                );
                continue;
            }
            $declaredPities[$entry->id] = $entry->origin === PullOrigin::Recorded && $recorded === $record->pity ? null : $record->pity;
        }
        if ($conflicts !== []) {
            return new SRankHistoryBannerPlan(records: $records, conflicts: $conflicts, warnings: $warnings);
        }
        foreach ($declaredPities as $id => $declaredPity) {
            $byId[$id] = self::withDeclaredPity($byId[$id], $declaredPity);
        }
        $simulated = self::sortEntries(array_values($byId), $seqs);

        $plan = $this->planner->plan($simulated);
        foreach ($plan->warnings as $warning) {
            $conflicts[] = 'The missing pulls do not fit: '.$warning;
        }
        $result = self::applyPlan($simulated, $seqs, $plan, $nextId);
        array_push($conflicts, ...$this->findPositionMismatches($records, $result));

        // More pulls than both the stored rows and the official total: stored rows were pushed after an S and made-up pulls
        // took their place (a total above the official one alone is fine: pulls made since the history was updated)
        $countPulls = static fn (array $rows): int => \count(array_filter($rows, static fn (PullTimelineEntry $entry): bool => $entry->countsAsPull));
        $storedPulls = $countPulls($entries);
        $pulls = $countPulls($result);
        if ($banner->totalPulls !== null && $pulls > max($storedPulls, $banner->totalPulls)) {
            $conflicts[] = \sprintf(
                '%d pulls after the import, more than the %d stored and the %d of the official history: stored pulls would end up after an S (e.g. two sources one hour apart). If you pulled since the official history was last updated, import again later.',
                $pulls,
                $storedPulls,
                $banner->totalPulls,
            );
        }
        if ($conflicts !== []) {
            return new SRankHistoryBannerPlan(records: $records, conflicts: $conflicts, warnings: $warnings);
        }

        $updates = [];
        $overwrites = [];
        foreach ($pairs as $recordIndex => $storedIndex) {
            $record = $records[$recordIndex];
            $entry = $stored[$storedIndex];
            $declaredPity = $declaredPities[$entry->id];
            $updates[] = new SRankHistoryRowUpdate($entry->id, $record, $entry->origin !== PullOrigin::Recorded, $declaredPity);

            if ($entry->origin === PullOrigin::Manual) {
                $overwrites[] = \sprintf(
                    'Manual S "%s" (%s, pity %s) replaced by the official one (%s, pity %d).',
                    $entry->name ?? '?',
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                    $entry->declaredPity ?? '?',
                    $record->pulledAt->format(ManualPull::DATE_FORMAT),
                    $record->pity,
                );
            } elseif ($entry->origin === PullOrigin::Recorded && $entry->declaredPity !== null && $entry->declaredPity !== $declaredPity
                // Equal to the official pity: written by an earlier import (merged official S), not entered by the user
                && $entry->declaredPity !== $record->pity) {
                $overwrites[] = \sprintf(
                    'Declared pity of the S "%s" of %s: %d replaced by %s.',
                    $entry->name ?? '?',
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                    $entry->declaredPity,
                    $declaredPity === null ? 'none (no pull is missing before it)' : \sprintf('the official pity %d', $declaredPity),
                );
            }

            $offset = $entry->pulledAt->getTimestamp() - $record->pulledAt->getTimestamp();
            if ($entry->origin === PullOrigin::Recorded && $offset !== 0) {
                $warnings[] = \sprintf(
                    'The S "%s" is stored at %s, the official history says %s (%+d s): the stored date is kept.',
                    $record->name,
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                    $record->pulledAt->format(ManualPull::DATE_FORMAT),
                    $offset,
                );
            }
        }

        // Totals that differ otherwise do not stop the import: pulls made since the history was updated, or missing pulls
        // after the last S
        if ($banner->totalPulls !== null && $banner->totalPulls !== $pulls) {
            $warnings[] = \sprintf('%d pulls after the import, the official history counts %d.', $pulls, $banner->totalPulls);
        }
        if ($banner->sCount !== null && $banner->sCount !== \count($records)) {
            $warnings[] = \sprintf('The official history lists %d S, but counts %d.', \count($records), $banner->sCount);
        }

        return new SRankHistoryBannerPlan(
            records: $records,
            creates: $creates,
            updates: $updates,
            overwrites: $overwrites,
            warnings: $warnings,
            realignNeeded: !$plan->isEmpty(),
        );
    }

    /**
     * The records whose pull number is not an S of the same item in the given rows (provider order, counted like the
     * pity: only the rows that count as a pull). Used on the simulation and, by the providers, after writing.
     *
     * @param list<SRankHistoryRecord> $records
     * @param list<PullTimelineEntry>  $entries
     *
     * @return list<string> One message per record out of place
     */
    public function findPositionMismatches(array $records, array $entries): array
    {
        $atPosition = [];
        $position = 0;
        foreach ($entries as $entry) {
            if ($entry->countsAsPull) {
                $atPosition[++$position] = $entry;
            }
        }

        $mismatches = [];
        foreach ($records as $record) {
            $entry = $atPosition[$record->pullNumber] ?? null;
            if ($entry !== null && $entry->origin !== PullOrigin::Filler && $entry->isS
                && PullGapPlanner::isSameItem($record->itemId, $record->name, $entry->itemId, $entry->name)) {
                continue;
            }
            $mismatches[] = \sprintf(
                'Pull #%d should be the S "%s" of %s, but it is %s.',
                $record->pullNumber,
                $record->name,
                $record->pulledAt->format(ManualPull::DATE_FORMAT),
                match (true) {
                    $entry === null => \sprintf('missing (%d pulls)', $position),
                    $entry->origin === PullOrigin::Filler => 'an unknown pull',
                    !$entry->isS => \sprintf('"%s", not an S', $entry->name ?? '?'),
                    default => \sprintf('the S "%s"', $entry->name ?? '?'),
                },
            );
        }

        return $mismatches;
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     *
     * @return list<SRankHistoryRecord> Records by pull number, exact duplicates dropped
     */
    private function checkChain(SRankHistoryBanner $banner, int $sMaxPity, array &$errors, array &$warnings): array
    {
        $byNumber = [];
        foreach ($banner->records as $record) {
            $known = $byNumber[$record->pullNumber] ?? null;
            if ($known === null) {
                $byNumber[$record->pullNumber] = $record;
            } elseif (self::isSameRecord($known, $record)) {
                $warnings[] = \sprintf('Pull #%d is listed twice by the official history.', $record->pullNumber);
            } else {
                $errors[] = \sprintf('Pull #%d is listed twice with different values ("%s", "%s").', $record->pullNumber, $known->name, $record->name);
            }
        }
        ksort($byNumber);
        $records = array_values($byNumber);

        $previous = null;
        $secondStart = null;
        foreach ($records as $record) {
            $distance = $record->pullNumber - ($previous?->pullNumber ?? 0);
            if ($record->pullNumber < 1) {
                $errors[] = \sprintf('The S "%s" has no valid pull number (%d).', $record->name, $record->pullNumber);
            } elseif ($record->pity !== $distance) {
                $errors[] = \sprintf('The pity of pull #%d ("%s") is %d, but it is %d pulls after the previous S.', $record->pullNumber, $record->name, $record->pity, $distance);
            }
            if ($record->pity > $sMaxPity) {
                $errors[] = \sprintf('The pity of pull #%d ("%s") is %d, above the %d of the banner.', $record->pullNumber, $record->name, $record->pity, $sMaxPity);
            }

            if ($previous !== null && $record->pulledAt->getTimestamp() < $previous->pulledAt->getTimestamp()) {
                $errors[] = \sprintf('Pull #%d is dated before pull #%d.', $record->pullNumber, $previous->pullNumber);
            }
            if ($previous === null || $record->pulledAt->getTimestamp() !== $previous->pulledAt->getTimestamp()) {
                $secondStart = $record;
            } elseif ($record->pullNumber - $secondStart->pullNumber > self::MAX_PULLS_APART_IN_ONE_SECOND) {
                $errors[] = \sprintf(
                    'Pulls #%d and #%d share the second %s but are %d pulls apart: they cannot belong to one 10-pull.',
                    $secondStart->pullNumber,
                    $record->pullNumber,
                    $record->pulledAt->format(ManualPull::DATE_FORMAT),
                    $record->pullNumber - $secondStart->pullNumber,
                );
            }
            $previous = $record;
        }

        if ($previous !== null && $banner->totalPulls !== null && $previous->pullNumber > $banner->totalPulls) {
            $errors[] = \sprintf('The last S is pull #%d, but the official history counts %d pulls.', $previous->pullNumber, $banner->totalPulls);
        }

        return $records;
    }

    /**
     * Field by field, strictly: a rate-up flag null and false, or ids "1001" and "01001", are different listings.
     */
    private static function isSameRecord(SRankHistoryRecord $a, SRankHistoryRecord $b): bool
    {
        return $a->itemId === $b->itemId
            && $a->name === $b->name
            && $a->itemType === $b->itemType
            && $a->pullNumber === $b->pullNumber
            && $a->pity === $b->pity
            && $a->pulledAt->getTimestamp() === $b->pulledAt->getTimestamp()
            && $a->isUp === $b->isUp;
    }

    /**
     * Order-preserving pairs of records and stored S with the most pairs, then the smallest total date distance.
     *
     * @param list<SRankHistoryRecord> $records By pull number
     * @param list<PullTimelineEntry>  $stored  Non-filler S rows, in timeline order
     *
     * @return array<int, int> Record index => stored index, both increasing
     */
    private function align(array $records, array $stored): array
    {
        $n = \count($records);
        $m = \count($stored);
        $costs = [];
        for ($i = 0; $i < $n; ++$i) {
            for ($j = 0; $j < $m; ++$j) {
                $costs[$i][$j] = self::pairCost($records[$i], $stored[$j]);
            }
        }

        // $best[$i][$j]: [pairs, total distance] of the records from $i and the stored S from $j
        $best = array_fill(0, $n + 1, array_fill(0, $m + 1, [0, 0]));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $value = self::better($best[$i + 1][$j], $best[$i][$j + 1]);
                if ($costs[$i][$j] !== null) {
                    $value = self::better([$best[$i + 1][$j + 1][0] + 1, $best[$i + 1][$j + 1][1] + $costs[$i][$j]], $value);
                }
                $best[$i][$j] = $value;
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($costs[$i][$j] !== null && [$best[$i + 1][$j + 1][0] + 1, $best[$i + 1][$j + 1][1] + $costs[$i][$j]] === $best[$i][$j]) {
                $pairs[$i++] = $j++;
            } elseif ($best[$i + 1][$j] === $best[$i][$j]) {
                ++$i;
            } else {
                ++$j;
            }
        }

        return $pairs;
    }

    /**
     * Distance in seconds between a record and a stored S that can be the same pull, null when they cannot.
     */
    private static function pairCost(SRankHistoryRecord $record, PullTimelineEntry $entry): ?int
    {
        if (!PullGapPlanner::isSameItem($record->itemId, $record->name, $entry->itemId, $entry->name)) {
            return null;
        }
        $distance = abs($entry->pulledAt->getTimestamp() - $record->pulledAt->getTimestamp());

        return match ($entry->origin) {
            PullOrigin::Recorded => $distance <= ManualPull::MERGE_WINDOW_SECONDS ? $distance : null,
            PullOrigin::Official => $entry->pullNumber === $record->pullNumber ? 0 : null,
            // The date of a manual S is the user's estimate: the order and the item decide
            PullOrigin::Manual => $distance,
            PullOrigin::Filler => null,
        };
    }

    /**
     * More pairs first, then the smaller distance; on a tie the first argument.
     *
     * @param array{0: int, 1: int} $a
     * @param array{0: int, 1: int} $b
     *
     * @return array{0: int, 1: int}
     */
    private static function better(array $a, array $b): array
    {
        return $a[0] > $b[0] || ($a[0] === $b[0] && $a[1] <= $b[1]) ? $a : $b;
    }

    private static function officialEntry(int $id, SRankHistoryRecord $record, bool $countsAsPull): PullTimelineEntry
    {
        return new PullTimelineEntry(
            id: $id,
            pulledAt: $record->pulledAt,
            origin: PullOrigin::Official,
            isS: true,
            countsAsPull: $countsAsPull,
            declaredPity: $record->pity,
            itemId: $record->itemId,
            name: $record->name,
            pullNumber: $record->pullNumber,
        );
    }

    private static function withDeclaredPity(PullTimelineEntry $entry, ?int $declaredPity): PullTimelineEntry
    {
        return new PullTimelineEntry(
            id: $entry->id,
            pulledAt: $entry->pulledAt,
            origin: $entry->origin,
            isS: $entry->isS,
            countsAsPull: $entry->countsAsPull,
            declaredPity: $declaredPity,
            itemId: $entry->itemId,
            name: $entry->name,
            pullNumber: $entry->pullNumber,
        );
    }

    /**
     * Provider order: date to the second, unnumbered rows before numbered ones (by number), then the stored order.
     *
     * @param list<PullTimelineEntry> $entries
     * @param array<int, int>         $seqs    Entry id => stored position (rows created later come after)
     *
     * @return list<PullTimelineEntry>
     */
    private static function sortEntries(array $entries, array $seqs): array
    {
        usort(
            $entries,
            static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => [$a->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($a->pullNumber), $seqs[$a->id] ?? PHP_INT_MAX, $a->id]
                <=> [$b->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($b->pullNumber), $seqs[$b->id] ?? PHP_INT_MAX, $b->id],
        );

        return $entries;
    }

    /**
     * The rows once the plan is applied, in provider order (like each provider's ManualPullService::realign()).
     *
     * @param list<PullTimelineEntry> $entries
     * @param array<int, int>         $seqs
     *
     * @return list<PullTimelineEntry>
     */
    private static function applyPlan(array $entries, array $seqs, FillerPlan $plan, int $nextId): array
    {
        $deleted = array_flip($plan->fillerDeletes);
        $result = [];
        foreach ($entries as $entry) {
            if (isset($plan->merges[$entry->id]) || isset($deleted[$entry->id])) {
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

        return self::sortEntries($result, $seqs);
    }

    /**
     * @param list<PullTimelineEntry> $entries
     *
     * @return list<string> Seconds holding both numbered and unnumbered non-filler rows
     */
    private static function findMixedSeconds(array $entries): array
    {
        $seconds = [];
        foreach ($entries as $entry) {
            if ($entry->origin === PullOrigin::Filler) {
                continue;
            }
            $second = $entry->pulledAt->format(ManualPull::DATE_FORMAT);
            $seconds[$second][$entry->pullNumber !== null ? 'numbered' : 'unnumbered'] = true;
        }

        return array_keys(array_filter($seconds, static fn (array $kinds): bool => \count($kinds) === 2));
    }
}
