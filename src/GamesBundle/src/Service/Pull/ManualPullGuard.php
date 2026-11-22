<?php

namespace App\GamesBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;

/**
 * Shared rules of the manual pull operations, checked against the entries of ONE banner group
 * (provider chronological order, see PullTimelineEntry).
 */
class ManualPullGuard
{
    public function __construct(
        private readonly PullGapPlanner $planner,
    ) {
    }

    /**
     * Operation A: declare (or clear, with a null pity) the real pity of an S.
     *
     * @param list<PullTimelineEntry> $entries
     *
     * @throws ManualPullViolationException
     */
    public function assertCanDeclare(array $entries, int $sId, ?int $pity, int $sMaxPity): void
    {
        $target = $this->findS($entries, $sId);
        if ($target === null) {
            throw new ManualPullViolationException(
                ManualPullViolationException::INVALID_TARGET,
                \sprintf('Pull #%d is not an S pull of this banner.', $sId),
            );
        }

        if ($pity === null) {
            if ($target->origin === PullOrigin::Manual) {
                throw new ManualPullViolationException(
                    ManualPullViolationException::PITY_REQUIRED,
                    'A manual S pull always has a pity: delete the pull instead.',
                );
            }

            return;
        }

        // Only the windows are read: the suggested dates (which depend on "now") are ignored
        $window = $this->planner->describe($entries, new \DateTimeImmutable())['windows'][$sId];
        $recorded = $window->recordedPity;

        if ($pity < $recorded || $pity > $sMaxPity) {
            throw new ManualPullViolationException(
                ManualPullViolationException::PITY_OUT_OF_RANGE,
                \sprintf('The pity must be between %d (recorded pulls) and %d.', $recorded, $sMaxPity),
            );
        }

        if ($pity > $recorded && !$window->hasRoom) {
            throw new ManualPullViolationException(
                ManualPullViolationException::NO_ROOM,
                'There is no room for unknown pulls before this S: its neighbouring pulls are too close in time.',
            );
        }
    }

    /**
     * Operation B: add a manual S at the start of the anchor's window (or of the open window
     * after the last S when there is no anchor).
     *
     * @param list<PullTimelineEntry> $entries
     * @param \DateTimeImmutable      $now     Latest accepted pull date (the caller shifts it when it needs a tolerance)
     *
     * @throws ManualPullViolationException
     */
    public function assertCanCreate(
        array $entries,
        ?int $anchorId,
        \DateTimeImmutable $pulledAt,
        int $pity,
        int $sMaxPity,
        ?string $itemId,
        string $name,
        bool $allowDuplicate,
        \DateTimeImmutable $now,
    ): void {
        if ($anchorId !== null && $this->findS($entries, $anchorId) === null) {
            throw new ManualPullViolationException(
                ManualPullViolationException::INVALID_ANCHOR,
                \sprintf('Pull #%d is not an S pull of this banner.', $anchorId),
            );
        }

        $description = $this->planner->describe($entries, $now);
        $window = $anchorId === null ? $description['open'] : $description['windows'][$anchorId];

        if ($pity < 1 || $pity > $sMaxPity) {
            throw new ManualPullViolationException(
                ManualPullViolationException::PITY_OUT_OF_RANGE,
                \sprintf('The pity must be between 1 and %d.', $sMaxPity),
            );
        }

        $at = $pulledAt->getTimestamp();
        $after = $window->insertAfter;
        $before = $window->insertBefore;
        if (($after !== null && $at <= $after->getTimestamp()) || ($before !== null && $at >= $before->getTimestamp())) {
            throw new ManualPullViolationException(
                ManualPullViolationException::DATE_OUT_OF_RANGE,
                \sprintf(
                    'The pull date must be after %s and before %s.',
                    $after?->format(ManualPull::DATE_FORMAT) ?? 'the start of the history',
                    $before?->format(ManualPull::DATE_FORMAT) ?? 'now',
                ),
            );
        }

        if ($at > $now->getTimestamp()) {
            throw new ManualPullViolationException(
                ManualPullViolationException::DATE_IN_FUTURE,
                'The pull date cannot be in the future.',
            );
        }

        // The new S closes a window that holds only itself: its pity - 1 fillers go between the previous S and it
        if ($pity > 1 && $after !== null && $at - $after->getTimestamp() < PullGapPlanner::MIN_GAP_SECONDS) {
            throw new ManualPullViolationException(
                ManualPullViolationException::NO_ROOM,
                \sprintf(
                    'There is no room for unknown pulls before this S: choose a date at least %d seconds after %s.',
                    PullGapPlanner::MIN_GAP_SECONDS,
                    $after->format(ManualPull::DATE_FORMAT),
                ),
            );
        }

        if ($allowDuplicate) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry->origin !== PullOrigin::Recorded || !$entry->isS) {
                continue;
            }
            if (abs($entry->pulledAt->getTimestamp() - $at) > ManualPull::MERGE_WINDOW_SECONDS) {
                continue;
            }
            if (!PullGapPlanner::isSameItem($itemId, $name, $entry->itemId, $entry->name)) {
                continue;
            }

            throw new ManualPullViolationException(
                ManualPullViolationException::POSSIBLE_DUPLICATE,
                \sprintf(
                    'A recorded S pull of "%s" exists on %s. Is this another copy?',
                    $entry->name ?? $name,
                    $entry->pulledAt->format(ManualPull::DATE_FORMAT),
                ),
            );
        }
    }

    /**
     * @param list<PullTimelineEntry> $entries
     */
    private function findS(array $entries, int $id): ?PullTimelineEntry
    {
        foreach ($entries as $entry) {
            if ($entry->id === $id) {
                return $entry->isS && $entry->origin !== PullOrigin::Filler ? $entry : null;
            }
        }

        return null;
    }
}
