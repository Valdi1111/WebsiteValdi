<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * An endgame mode as shown in an embed row: its results in the current period, the end of that period, and the
 * variants to leave as an empty column (e.g. those whose reminder is turned off).
 */
final readonly class EndgameModeStatus
{
    /**
     * @param list<GameEndgame> $endgames       results of the mode, one per variant
     * @param list<string>      $hiddenVariants keys of the variants shown as an empty column
     */
    public function __construct(
        public EndgameDefinition $definition,
        public array $endgames,
        public \DateTimeImmutable $endTime,
        public array $hiddenVariants = [],
    ) {
    }
}
