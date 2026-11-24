<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * One separately reminded part of an endgame mode, e.g. the normal and the Hard mode of Deadly Assault: same request
 * and same period, but its own profile setting.
 *
 * A variant played at a difficulty the player picks (e.g. Imaginarium Theater) declares its levels: its setting then
 * takes a level key in place of "full_clear", and the game tells the highest level completed
 * (GameEndgame::setCompletedLevel()).
 */
final readonly class EndgameVariant
{
    /** Key of the single variant of a mode without variants */
    public const string DEFAULT_KEY = 'reminder';

    /**
     * @var list<EndgameLevel>
     */
    public array $levels;

    /**
     * @param string             $key    setting key inside the mode's profile settings, e.g. "reminder", "hard_reminder"
     * @param string             $name   label in the settings and in the notification, e.g. "Deadly Assault (Hard)"
     * @param list<EndgameLevel> $levels difficulties the player can be reminded up to, easiest first; none = the
     *                                   three EndgameReminder values only
     */
    public function __construct(
        public string $key,
        public string $name,
        public EndgameReminder $defaultReminder = EndgameReminder::NotPlayed,
        array $levels = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(sprintf('Invalid endgame variant key "%s": use snake_case.', $key));
        }

        $levels = array_values($levels);
        $levelKeys = array_map(static fn (EndgameLevel $level) => $level->key, $levels);
        if (count($levelKeys) !== count(array_unique($levelKeys))) {
            throw new \InvalidArgumentException(sprintf('Endgame variant "%s" has duplicate level keys.', $key));
        }

        $this->levels = $levels;
    }

    public function hasLevels(): bool
    {
        return $this->levels !== [];
    }

    public function getLevel(string $key): ?EndgameLevel
    {
        foreach ($this->levels as $level) {
            if ($level->key === $key) {
                return $level;
            }
        }

        return null;
    }

    /**
     * The hardest level, which Full Clear stands for on a variant with levels.
     */
    public function getTopLevel(): ?EndgameLevel
    {
        return $this->levels !== [] ? $this->levels[array_key_last($this->levels)] : null;
    }

    public function getDefaultTarget(): EndgameTarget
    {
        return $this->createTarget($this->defaultReminder);
    }

    /**
     * The target a stored or submitted setting stands for: one of the variant's level keys, or an EndgameReminder
     * value ("full_clear" meaning the top level on a variant with levels). Null for anything else.
     */
    public function findTarget(mixed $value): ?EndgameTarget
    {
        if (!is_string($value)) {
            return null;
        }

        $level = $this->getLevel($value);
        if ($level !== null) {
            return new EndgameTarget(EndgameReminder::FullClear, $level, $this->levels);
        }

        $reminder = EndgameReminder::tryFrom($value);

        return $reminder !== null ? $this->createTarget($reminder) : null;
    }

    private function createTarget(EndgameReminder $reminder): EndgameTarget
    {
        if ($reminder === EndgameReminder::FullClear && $this->hasLevels()) {
            return new EndgameTarget($reminder, $this->getTopLevel(), $this->levels);
        }

        return new EndgameTarget($reminder);
    }
}
