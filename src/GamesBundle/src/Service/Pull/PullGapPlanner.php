<?php

namespace App\GamesBundle\Service\Pull;

use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\Manual\FillerSlot;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\Manual\SWindow;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;

/**
 * Shared, provider-agnostic logic of the missing pulls feature.
 *
 * Works on the entries of ONE banner group, in the provider's chronological order. Filler
 * entries never influence the computation (windows, counts, boundaries): they are only
 * matched against the desired slots at the end, which makes plan() idempotent.
 *
 * All dates are derived from a neighbouring row with setTimestamp(), so they keep the
 * timezone object of the provider's own values (wall-clock values stored as UTC stay UTC).
 *
 * Pull numbers (PullTimelineEntry::$pullNumber) only matter where two numbered S are closer than
 * MIN_GAP_SECONDS, i.e. the S of one 10-pull: their fillers then go in the second of the earlier
 * S with consecutive numbers. Without numbers the plan is the same as before they existed.
 */
class PullGapPlanner
{
    /** Minimum span between two neighbours for fillers to fit strictly between them */
    public const int MIN_GAP_SECONDS = 2;

    /** The suggested date of a manual S is this many seconds before the next pull */
    private const int SUGGESTION_OFFSET_SECONDS = 60;

    /** Without a next pull, the suggested date is at most this many seconds after the previous S */
    private const int OPEN_SUGGESTION_SECONDS = 86400;

    /**
     * Computes what must change for the banner to reach its expected state: manual S rows merged
     * into the recorded S that arrived later, and the filler rows each declared pity requires.
     *
     * Applying the plan and planning again on the result always gives an empty plan.
     *
     * @param list<PullTimelineEntry> $entries Entries of one banner group, oldest first
     */
    public function plan(array $entries): FillerPlan
    {
        [$merges, $declaredPityUpdates] = $this->computeMerges($entries);

        $effective = [];
        foreach ($entries as $entry) {
            if (isset($merges[$entry->id])) {
                continue;
            }
            if (isset($declaredPityUpdates[$entry->id])) {
                $entry = $this->withDeclaredPity($entry, $declaredPityUpdates[$entry->id]);
            }
            $effective[] = $entry;
        }

        $slots = [];
        $warnings = [];
        $mixedSeconds = $this->findMixedSeconds($effective);
        foreach ($mixedSeconds as $pulledAt) {
            $warnings[] = \sprintf(
                'The pulls of %s mix rows with and without a pull number: their order inside that second is unknown, so no filler is numbered there.',
                $pulledAt->format(ManualPull::DATE_FORMAT),
            );
        }

        foreach ($this->splitWindows($effective)['closed'] as $window) {
            $s = $window['s'];
            if ($s->declaredPity === null) {
                continue;
            }

            $needed = max(0, $s->declaredPity - $window['recorded']);
            if ($needed === 0) {
                continue;
            }

            $gap = $this->findGap($window['boundaries']);
            if ($gap !== null) {
                foreach ($this->spreadInGap($gap[0], $gap[1], $needed) as $pulledAt) {
                    $slots[] = new FillerSlot($pulledAt, $s->id);
                }
                continue;
            }

            $numbered = $this->findNumberedGap($window, $mixedSeconds);
            if ($numbered !== null && $numbered['room'] >= $needed) {
                for ($k = 1; $k <= $needed; ++$k) {
                    $slots[] = new FillerSlot($numbered['lower']->pulledAt, $s->id, (int) $numbered['lower']->pullNumber + $k);
                }
                continue;
            }

            $warnings[] = $numbered === null
                ? \sprintf(
                    'No room for %d unknown pull(s) before S #%d: its neighbours are less than %d seconds apart.',
                    $needed,
                    $s->id,
                    self::MIN_GAP_SECONDS,
                )
                : \sprintf(
                    'No room for %d unknown pull(s) before S #%d: its pull number leaves room for %d after the previous S.',
                    $needed,
                    $s->id,
                    max(0, $numbered['room']),
                );
        }

        return $this->matchFillers($entries, $slots, $merges, $declaredPityUpdates, $warnings);
    }

    /**
     * Current state of every S window, without merges applied (limits of the manual pull forms).
     *
     * @param list<PullTimelineEntry> $entries Entries of one banner group, oldest first
     * @param \DateTimeImmutable      $now     Upper limit of the suggested dates
     * @return array{windows: array<int, SWindow>, open: SWindow} Windows keyed by S entry id (manual S included)
     */
    public function describe(array $entries, \DateTimeImmutable $now): array
    {
        $split = $this->splitWindows($entries);

        $windows = [];
        foreach ($split['closed'] as $window) {
            $windows[$window['s']->id] = $this->buildWindow($window, $window['s'], $now);
        }

        return [
            'windows' => $windows,
            'open' => $this->buildWindow($split['open'], null, $now),
        ];
    }

    /**
     * Lowercase, then keep only ASCII letters and digits ("Hu Tao" and "hu-tao" match).
     */
    public static function normalizeName(?string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($name ?? ''));
    }

    /**
     * Whether two pulls are the same item: equal item ids when both have one, otherwise equal
     * normalized names. An empty normalized name (missing, or no ASCII letter at all) never matches.
     */
    public static function isSameItem(?string $leftItemId, ?string $leftName, ?string $rightItemId, ?string $rightName): bool
    {
        if ($leftItemId !== null && $leftItemId !== '' && $rightItemId !== null && $rightItemId !== '') {
            return $leftItemId === $rightItemId;
        }

        $left = self::normalizeName($leftName);

        return $left !== '' && $left === self::normalizeName($rightName);
    }

    /**
     * Pairs each manual S with the nearest recorded S of the same item inserted after it. Manual S are
     * visited in timeline order and ties go to the earliest candidate in the timeline, so two copies of
     * one item in one 10-pull pair up in order (first with first).
     *
     * A recorded S that already carries a declared pity is not a candidate: it has been claimed by
     * an earlier merge (realign runs after every insertion), and accepting it would merge another
     * manual copy of the same item on the next run, breaking idempotency.
     *
     * @param list<PullTimelineEntry> $entries
     * @return array{0: array<int, int>, 1: array<int, int>} Merges (manual id => recorded id) and declared pity updates (recorded id => pity)
     */
    private function computeMerges(array $entries): array
    {
        $recordedS = array_values(array_filter(
            $entries,
            static fn (PullTimelineEntry $entry): bool => $entry->origin === PullOrigin::Recorded && $entry->isS && $entry->declaredPity === null,
        ));

        $merges = [];
        $declaredPityUpdates = [];
        $used = [];
        foreach ($entries as $manual) {
            if ($manual->origin !== PullOrigin::Manual || !$manual->isS) {
                continue;
            }

            $best = null;
            $bestDistance = null;
            foreach ($recordedS as $candidate) {
                if ($candidate->id <= $manual->id || isset($used[$candidate->id])) {
                    continue;
                }
                if (!self::isSameItem($manual->itemId, $manual->name, $candidate->itemId, $candidate->name)) {
                    continue;
                }

                $distance = abs($candidate->pulledAt->getTimestamp() - $manual->pulledAt->getTimestamp());
                if ($distance > ManualPull::MERGE_WINDOW_SECONDS) {
                    continue;
                }
                // Nearest in time wins; on a tie, the earliest in the timeline (candidates are visited in order). Not the
                // lowest id: a sync inserts the newest pulls first, so inside a 10-pull the ids decrease with time, and
                // two manual copies of one item would take the two recorded copies the other way round.
                if ($best === null || $distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }

            if ($best === null) {
                continue;
            }

            $used[$best->id] = true;
            $merges[$manual->id] = $best->id;
            if ($manual->declaredPity !== null) {
                $declaredPityUpdates[$best->id] = $manual->declaredPity;
            }
        }

        return [$merges, $declaredPityUpdates];
    }

    /**
     * Reuses the existing fillers for the desired slots, both in chronological order.
     *
     * @param list<PullTimelineEntry> $entries
     * @param list<FillerSlot>        $slots
     * @param array<int, int>         $merges
     * @param array<int, int>         $declaredPityUpdates
     * @param list<string>            $warnings
     */
    private function matchFillers(array $entries, array $slots, array $merges, array $declaredPityUpdates, array $warnings): FillerPlan
    {
        $fillers = array_values(array_filter(
            $entries,
            static fn (PullTimelineEntry $entry): bool => $entry->origin === PullOrigin::Filler,
        ));
        // Unnumbered before numbered inside a second, like the providers' order (without numbers: date, then id)
        usort(
            $fillers,
            static fn (PullTimelineEntry $a, PullTimelineEntry $b): int => [$a->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($a->pullNumber), $a->id]
                <=> [$b->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($b->pullNumber), $b->id],
        );
        usort(
            $slots,
            static fn (FillerSlot $a, FillerSlot $b): int => [$a->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($a->pullNumber)]
                <=> [$b->pulledAt->getTimestamp(), ...PullTimelineEntry::pullNumberOrderKey($b->pullNumber)],
        );

        $moves = [];
        $pullNumbers = [];
        $creates = [];
        foreach ($slots as $index => $slot) {
            $filler = $fillers[$index] ?? null;
            if ($filler === null) {
                $creates[] = $slot;
                continue;
            }
            if ($filler->pulledAt->getTimestamp() !== $slot->pulledAt->getTimestamp()) {
                $moves[$filler->id] = $slot->pulledAt;
            }
            if ($filler->pullNumber !== $slot->pullNumber) {
                $pullNumbers[$filler->id] = $slot->pullNumber;
            }
        }

        $deletes = [];
        foreach (\array_slice($fillers, \count($slots)) as $filler) {
            $deletes[] = $filler->id;
        }

        return new FillerPlan($merges, $declaredPityUpdates, $moves, $deletes, $creates, $warnings, $pullNumbers);
    }

    /**
     * Seconds holding both numbered and unnumbered non-filler rows. Numbers exist only on rows built from an official
     * S-rank history, whose seconds hold no recorded row, so this should never happen; when it does, the order of
     * those rows is unknown and no numbered gap touching that second is used.
     *
     * @param list<PullTimelineEntry> $entries
     * @return array<int, \DateTimeImmutable> Timestamp => a date of that second, in chronological order
     */
    private function findMixedSeconds(array $entries): array
    {
        $seconds = [];
        foreach ($entries as $entry) {
            if ($entry->origin === PullOrigin::Filler) {
                continue;
            }
            $timestamp = $entry->pulledAt->getTimestamp();
            $seconds[$timestamp] ??= ['at' => $entry->pulledAt, 'numbered' => false, 'unnumbered' => false];
            $seconds[$timestamp][$entry->pullNumber !== null ? 'numbered' : 'unnumbered'] = true;
        }

        $mixed = [];
        foreach ($seconds as $timestamp => $second) {
            if ($second['numbered'] && $second['unnumbered']) {
                $mixed[$timestamp] = $second['at'];
            }
        }

        return $mixed;
    }

    /**
     * The gap between two numbered S closer than MIN_GAP_SECONDS (the S of one 10-pull share its second): fillers fit
     * there by pull number, in the second of the earlier S. Only for a window holding nothing but its S, after a
     * numbered previous S, and when neither second mixes numbered and unnumbered rows.
     *
     * @param array{s: PullTimelineEntry, previous: ?PullTimelineEntry, boundaries: list<?\DateTimeImmutable>} $window
     * @param array<int, \DateTimeImmutable>                                                                $mixedSeconds
     * @return array{lower: PullTimelineEntry, room: int}|null Previous S and the pull numbers free before the S (may be < 1)
     */
    private function findNumberedGap(array $window, array $mixedSeconds): ?array
    {
        $lower = $window['previous'];
        $upper = $window['s'];
        if ($lower === null || \count($window['boundaries']) !== 2 || $lower->pullNumber === null || $upper->pullNumber === null) {
            return null;
        }
        if (isset($mixedSeconds[$lower->pulledAt->getTimestamp()]) || isset($mixedSeconds[$upper->pulledAt->getTimestamp()])) {
            return null;
        }

        return ['lower' => $lower, 'room' => $upper->pullNumber - $lower->pullNumber - 1];
    }

    /**
     * Splits the non-filler entries into S windows, keeping the given order.
     *
     * @param list<PullTimelineEntry> $entries
     * @return array{
     *     closed: list<array{s: PullTimelineEntry, previous: ?PullTimelineEntry, first: PullTimelineEntry, recorded: int, boundaries: list<?\DateTimeImmutable>}>,
     *     open: array{previous: ?PullTimelineEntry, first: ?PullTimelineEntry, recorded: int, boundaries: list<?\DateTimeImmutable>}
     * }
     */
    private function splitWindows(array $entries): array
    {
        $closed = [];
        $previous = null;
        $first = null;
        $recorded = 0;
        // Previous S (null = start of history), then every non-filler row of the window
        $boundaries = [null];

        foreach ($entries as $entry) {
            if ($entry->origin === PullOrigin::Filler) {
                continue;
            }

            $first ??= $entry;
            $boundaries[] = $entry->pulledAt;
            if ($entry->countsAsPull) {
                ++$recorded;
            }
            if (!$entry->isS) {
                continue;
            }

            $closed[] = [
                's' => $entry,
                'previous' => $previous,
                'first' => $first,
                'recorded' => $recorded,
                'boundaries' => $boundaries,
            ];
            $previous = $entry;
            $first = null;
            $recorded = 0;
            $boundaries = [$entry->pulledAt];
        }

        return [
            'closed' => $closed,
            'open' => [
                'previous' => $previous,
                'first' => $first,
                'recorded' => $recorded,
                'boundaries' => $boundaries,
            ],
        ];
    }

    /**
     * @param array{previous: ?PullTimelineEntry, first: ?PullTimelineEntry, recorded: int, boundaries: list<?\DateTimeImmutable>} $window
     */
    private function buildWindow(array $window, ?PullTimelineEntry $s, \DateTimeImmutable $now): SWindow
    {
        $insertAfter = $window['previous']?->pulledAt;
        $insertBefore = $window['first']?->pulledAt;

        return new SWindow(
            sId: $s?->id,
            recordedPity: $window['recorded'],
            declaredPity: $s?->declaredPity,
            insertAfter: $insertAfter,
            insertBefore: $insertBefore,
            suggestedAt: $this->suggestDate($insertAfter, $insertBefore, $now),
            hasRoom: $this->findGap($window['boundaries']) !== null,
        );
    }

    /**
     * Chooses where the fillers of a window go: the gap without a lower bound (start of history)
     * when there is one, otherwise the widest gap of at least MIN_GAP_SECONDS (the earliest on a tie).
     *
     * @param list<?\DateTimeImmutable> $boundaries Previous S (null = start of history), then the non-filler rows up to the S
     * @return array{0: ?\DateTimeImmutable, 1: \DateTimeImmutable}|null Lower and upper bound, null when no gap is usable
     */
    private function findGap(array $boundaries): ?array
    {
        $count = \count($boundaries);
        if ($count < 2) {
            return null;
        }
        if ($boundaries[0] === null) {
            return [null, $boundaries[1]];
        }

        $best = null;
        $bestSpan = self::MIN_GAP_SECONDS - 1;
        for ($i = 1; $i < $count; ++$i) {
            $lower = $boundaries[$i - 1];
            $upper = $boundaries[$i];
            $span = $upper->getTimestamp() - $lower->getTimestamp();
            if ($span > $bestSpan) {
                $best = [$lower, $upper];
                $bestSpan = $span;
            }
        }

        return $best;
    }

    /**
     * Spreads the fillers evenly, strictly between the bounds (several may share a second).
     * Without a lower bound, they all go one second before the upper bound.
     *
     * @return list<\DateTimeImmutable>
     */
    private function spreadInGap(?\DateTimeImmutable $lower, \DateTimeImmutable $upper, int $count): array
    {
        if ($lower === null) {
            return array_fill(0, $count, $upper->setTimestamp($upper->getTimestamp() - 1));
        }

        $base = $lower->getTimestamp();
        $span = $upper->getTimestamp() - $base;

        $dates = [];
        for ($k = 1; $k <= $count; ++$k) {
            $offset = max(1, min($span - 1, intdiv($k * $span, $count + 1)));
            $dates[] = $lower->setTimestamp($base + $offset);
        }

        return $dates;
    }

    /**
     * Proposed date of a manual S: one minute before the next pull, or the midpoint when the
     * interval is narrower than two minutes. Without a next pull: one day after the previous S.
     * Never later than now. Null when no second fits strictly between the bounds.
     */
    private function suggestDate(?\DateTimeImmutable $after, ?\DateTimeImmutable $before, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $nowTs = $now->getTimestamp();
        $afterTs = $after?->getTimestamp();
        $beforeTs = $before?->getTimestamp();

        if ($beforeTs !== null) {
            $candidate = $afterTs === null || $beforeTs - $afterTs >= 2 * self::SUGGESTION_OFFSET_SECONDS
                ? $beforeTs - self::SUGGESTION_OFFSET_SECONDS
                : $afterTs + intdiv($beforeTs - $afterTs, 2);
        } else {
            $candidate = $afterTs === null ? $nowTs : $afterTs + self::OPEN_SUGGESTION_SECONDS;
        }
        $candidate = min($candidate, $nowTs);

        if (($afterTs !== null && $candidate <= $afterTs) || ($beforeTs !== null && $candidate >= $beforeTs)) {
            return null;
        }

        // Derive from a bound to keep the provider's timezone object
        return ($before ?? $after ?? $now)->setTimestamp($candidate);
    }

    private function withDeclaredPity(PullTimelineEntry $entry, int $declaredPity): PullTimelineEntry
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
}
