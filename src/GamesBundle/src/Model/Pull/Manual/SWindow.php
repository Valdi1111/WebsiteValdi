<?php

namespace App\GamesBundle\Model\Pull\Manual;

/**
 * State of one S pity window: the rows after the previous S (exclusive) up to an S (inclusive),
 * or the open window after the last S.
 */
final readonly class SWindow
{
    /**
     * @param int|null                $sId           S that closes the window, null for the open window after the last S
     * @param int                     $recordedPity  Non-filler rows of the window that count as a pull (the S included)
     * @param int|null                $declaredPity  Pity declared on the S (null for the open window)
     * @param \DateTimeImmutable|null $insertAfter   Pull date of the previous S (null = start of history)
     * @param \DateTimeImmutable|null $insertBefore  Pull date of the first non-filler row of the window (null = none)
     * @param \DateTimeImmutable|null $suggestedAt   Proposed date for a manual S inserted in this window (null = no valid second)
     * @param bool                    $hasRoom       Filler rows can be placed in the window
     */
    public function __construct(
        public ?int $sId,
        public int $recordedPity,
        public ?int $declaredPity,
        public ?\DateTimeImmutable $insertAfter,
        public ?\DateTimeImmutable $insertBefore,
        public ?\DateTimeImmutable $suggestedAt,
        public bool $hasRoom,
    ) {
    }

    /**
     * @return array{
     *     recorded_pity: int,
     *     declared_pity: int|null,
     *     insert_after: string|null,
     *     insert_before: string|null,
     *     suggested_at: string|null,
     *     has_room: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'recorded_pity' => $this->recordedPity,
            'declared_pity' => $this->declaredPity,
            'insert_after' => $this->insertAfter?->format(ManualPull::DATE_FORMAT),
            'insert_before' => $this->insertBefore?->format(ManualPull::DATE_FORMAT),
            'suggested_at' => $this->suggestedAt?->format(ManualPull::DATE_FORMAT),
            'has_room' => $this->hasRoom,
        ];
    }
}
