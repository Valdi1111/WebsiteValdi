<?php

namespace App\GamesBundle\Model\Endgame;

use App\GamesBundle\Model\Status\FetchedDataInterface;

/**
 * Endgame modes of a game profile as read by EndgameStatusBuilder, in the order of the definitions. Each mode is read
 * on its own: one that fails keeps its error, the others are still there.
 */
final readonly class EndgameReadout implements FetchedDataInterface
{
    /**
     * @param list<EndgameModeReadout> $modes
     * @param \DateTimeImmutable|null  $fetchedAt the oldest fetch time of the responses read
     */
    public function __construct(
        public array $modes,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /**
     * @return list<\Throwable> the errors of the modes that could not be read
     */
    public function getErrors(): array
    {
        $errors = [];
        foreach ($this->modes as $mode) {
            if ($mode->error !== null) {
                $errors[] = $mode->error;
            }
        }

        return $errors;
    }

    /**
     * Whether every mode read failed (an account-wide problem: expired cookie, private game record...). False when
     * no mode was read.
     */
    public function allFailed(): bool
    {
        return $this->modes !== [] && count($this->getErrors()) === count($this->modes);
    }
}
