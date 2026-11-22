<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * An endgame mode declared by a game (HasEndgamesInterface::getEndgameDefinitions()): its key is used both in the
 * bundle configuration (reminder window) and in the profile settings (EndgameReminder of each variant).
 */
final readonly class EndgameDefinition
{
    /** Reminder window used when the bundle configuration does not set one, in days of 24 hours */
    public const int DEFAULT_DAYS_BEFORE_END = 7;

    /**
     * The notification shows one row per mode: its variants, then the end of the period. A row has three columns.
     */
    public const int MAX_VARIANTS = 2;

    /**
     * @var non-empty-list<EndgameVariant>
     */
    public array $variants;

    /**
     * @param string               $key      configuration and settings key, e.g. "deadly_assault"
     * @param string               $name     label, e.g. "Deadly Assault"
     * @param list<EndgameVariant> $variants separately reminded parts; none = a single variant named like the mode
     */
    public function __construct(
        public string $key,
        public string $name,
        array $variants = [],
        public int $defaultDaysBeforeEnd = self::DEFAULT_DAYS_BEFORE_END,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(sprintf('Invalid endgame key "%s": use snake_case.', $key));
        }

        $variants = $variants !== [] ? array_values($variants) : [new EndgameVariant(EndgameVariant::DEFAULT_KEY, $name)];
        if (count($variants) > self::MAX_VARIANTS) {
            throw new \InvalidArgumentException(sprintf('Endgame "%s" has %d variants, at most %d fit in a notification row.', $key, count($variants), self::MAX_VARIANTS));
        }

        $keys = array_map(static fn (EndgameVariant $variant) => $variant->key, $variants);
        if (count($keys) !== count(array_unique($keys))) {
            throw new \InvalidArgumentException(sprintf('Endgame "%s" has duplicate variant keys.', $key));
        }

        $this->variants = $variants;
    }

    public function getVariant(string $key): ?EndgameVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->key === $key) {
                return $variant;
            }
        }

        return null;
    }
}
