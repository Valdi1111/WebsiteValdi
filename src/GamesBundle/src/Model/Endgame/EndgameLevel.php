<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * A difficulty of an endgame variant played at a difficulty the player picks, e.g. "Visionary" in Imaginarium
 * Theater. The player is reminded until a chosen level (or a harder one) is completed; the key is stored in the
 * profile settings in place of an EndgameReminder value, so it cannot be one.
 */
final readonly class EndgameLevel
{
    /**
     * @param string $key  setting value, e.g. "visionary"
     * @param string $name label in the settings and in the notification, e.g. "Visionary"
     */
    public function __construct(
        public string $key,
        public string $name,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(sprintf('Invalid endgame level key "%s": use snake_case.', $key));
        }

        if (EndgameReminder::tryFrom($key) !== null) {
            throw new \InvalidArgumentException(sprintf('Invalid endgame level key "%s": it is an EndgameReminder value.', $key));
        }
    }
}
