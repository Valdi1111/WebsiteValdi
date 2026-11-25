<?php

namespace App\GamesBundle\Model\Status;

/**
 * One section in the compact view of a profile. $attention marks something the player can act on now (stamina at the
 * threshold, dailies not done, expeditions back...), as the matching reminder would.
 */
final readonly class SummaryLine
{
    public function __construct(
        public string $label,
        public string $value,
        public string $icon,
        public bool $attention = false,
    ) {
    }
}
