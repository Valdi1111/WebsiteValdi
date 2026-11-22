<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * One separately reminded part of an endgame mode, e.g. the normal and the Hard mode of Deadly Assault: same request
 * and same period, but its own profile setting.
 */
final readonly class EndgameVariant
{
    /** Key of the single variant of a mode without variants */
    public const string DEFAULT_KEY = 'reminder';

    /**
     * @param string $key  setting key inside the mode's profile settings, e.g. "reminder", "hard_reminder"
     * @param string $name label in the settings and in the notification, e.g. "Deadly Assault (Hard)"
     */
    public function __construct(
        public string $key,
        public string $name,
        public EndgameReminder $defaultReminder = EndgameReminder::NotPlayed,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(sprintf('Invalid endgame variant key "%s": use snake_case.', $key));
        }
    }
}
