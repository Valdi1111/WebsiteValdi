<?php

namespace App\GamesBundle\Model\Status;

use App\GamesBundle\Model\Endgame\EndgameReadout;
use App\GamesBundle\Model\Endgame\EndgameTarget;

/**
 * Data of the endgames section: every mode as read, and the targets of the profile (its endgame settings, resolved
 * against the definitions of the game).
 */
final readonly class EndgamesData implements FetchedDataInterface
{
    /**
     * @param array<string, array<string, EndgameTarget>> $targets mode key => variant key => target
     */
    public function __construct(
        public EndgameReadout $readout,
        public array $targets,
    ) {
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->readout->fetchedAt;
    }
}
