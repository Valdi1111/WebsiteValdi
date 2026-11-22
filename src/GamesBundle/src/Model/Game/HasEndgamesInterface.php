<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\GameEndgame;

/**
 * The game exposes the player's progress in its rotating endgame modes, enabling the Endgames Reminder.
 *
 * The two static methods describe the modes without a service instance: the bundle configuration is built from them
 * while the container compiles (one reminder window per mode), and the profile settings use the same keys. Implement
 * them as final, so that test doubles of the game keep the real declarations.
 */
interface HasEndgamesInterface
{
    /**
     * Key of the game in the endgames reminder configuration, e.g. "zzz" (snake_case, unique per bundle).
     */
    public static function getEndgamesConfigKey(): string;

    /**
     * The endgame modes of the game, in the order of the notification.
     *
     * @return list<EndgameDefinition>
     */
    public static function getEndgameDefinitions(): array;

    /**
     * Player's progress in one mode for the current period: one GameEndgame per variant of the definition, tagged
     * with its variant key; variants the player has not unlocked are returned as locked. An empty list means the
     * response cannot be interpreted (e.g. an unknown format): no reminder. Throws when the request fails, so that
     * the reminder can tell a failing mode from a mode with nothing to remind.
     *
     * @return list<GameEndgame>
     */
    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account): array;
}
