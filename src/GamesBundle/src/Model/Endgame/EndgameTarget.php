<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * What the profile setting of one variant asks for (EndgameSettings::resolve()): an EndgameReminder, and for a
 * variant with levels the difficulty to complete. A level is a Full Clear up to that level: "remind me until I have
 * completed at least this difficulty".
 */
final readonly class EndgameTarget
{
    /**
     * @param EndgameLevel|null  $level  chosen difficulty, only with EndgameReminder::FullClear
     * @param list<EndgameLevel> $levels levels of the variant, easiest first, to compare a completed level with $level
     */
    public function __construct(
        public EndgameReminder $reminder,
        public ?EndgameLevel $level = null,
        public array $levels = [],
    ) {
        if ($level === null) {
            return;
        }

        if ($reminder !== EndgameReminder::FullClear) {
            throw new \InvalidArgumentException(sprintf('Endgame level "%s" needs the full_clear reminder, not "%s".', $level->key, $reminder->value));
        }

        if (self::findRank($levels, $level->key) === null) {
            throw new \InvalidArgumentException(sprintf('Endgame level "%s" is not one of the levels of the variant.', $level->key));
        }
    }

    /**
     * The value as stored in the profile settings and serialized: the level key, or the EndgameReminder value.
     */
    public function getValue(): string
    {
        return $this->level?->key ?? $this->reminder->value;
    }

    /**
     * Whether the level $levelKey is at least the chosen one. False without a chosen level, and for a missing or
     * undeclared level: an unknown progress never reaches a target.
     */
    public function isLevelReached(?string $levelKey): bool
    {
        if ($this->level === null || $levelKey === null) {
            return false;
        }

        $rank = self::findRank($this->levels, $levelKey);

        return $rank !== null && $rank >= self::findRank($this->levels, $this->level->key);
    }

    /**
     * @param list<EndgameLevel> $levels
     */
    private static function findRank(array $levels, string $key): ?int
    {
        foreach (array_values($levels) as $rank => $level) {
            if ($level->key === $key) {
                return $rank;
            }
        }

        return null;
    }
}
