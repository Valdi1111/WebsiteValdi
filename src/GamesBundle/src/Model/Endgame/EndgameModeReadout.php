<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * One endgame mode as read by EndgameStatusBuilder: its variants (one GameEndgame each, possibly fewer when the
 * response could not be read), or the error that kept it from being read.
 */
final readonly class EndgameModeReadout
{
    /**
     * @param list<GameEndgame> $endgames
     */
    public function __construct(
        public EndgameDefinition $definition,
        public array $endgames = [],
        public ?\Throwable $error = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }

    /**
     * End of the current period, shared by the variants of the mode; null when unknown (no current period).
     */
    public function getEndTime(): ?\DateTimeImmutable
    {
        foreach ($this->endgames as $endgame) {
            if ($endgame->getEndTime() !== null) {
                return $endgame->getEndTime();
            }
        }

        return null;
    }
}
