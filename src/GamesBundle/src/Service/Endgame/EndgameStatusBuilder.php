<?php

namespace App\GamesBundle\Service\Endgame;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameModeReadout;
use App\GamesBundle\Model\Endgame\EndgameReadout;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;

/**
 * Reads the endgame modes of a game profile without any filter: the callers decide what to show or remind (the
 * reminder keeps its settings, windows and logs).
 *
 * Each mode is read on its own: one that fails keeps its error in the readout and the others are still read. Nothing
 * is logged and nothing is thrown here, so every caller logs with its own rules (error messages can carry account
 * data).
 */
class EndgameStatusBuilder
{
    /**
     * @param list<EndgameDefinition>|null $definitions modes to read, in this order; null for every mode of the game
     * @param bool                         $fresh       skip the endgames cache: request every mode again
     */
    public function read(
        HasEndgamesInterface $gameService,
        RuntimeAccountDataInterface $account,
        ?array $definitions = null,
        bool $fresh = false,
    ): EndgameReadout
    {
        $modes = [];
        $fetchedAt = null;
        foreach ($definitions ?? $gameService::getEndgameDefinitions() as $definition) {
            try {
                $endgames = $gameService->fetchEndgame($definition, $account, $fresh);
            } catch (\Throwable $e) {
                $modes[] = new EndgameModeReadout($definition, error: $e);
                continue;
            }

            foreach ($endgames as $endgame) {
                // The oldest answer: the readout is never shown as newer than it is
                $endgameFetchedAt = $endgame->getFetchedAt();
                if ($endgameFetchedAt !== null && ($fetchedAt === null || $endgameFetchedAt < $fetchedAt)) {
                    $fetchedAt = $endgameFetchedAt;
                }
            }

            $modes[] = new EndgameModeReadout($definition, $endgames);
        }

        return new EndgameReadout($modes, $fetchedAt);
    }
}
