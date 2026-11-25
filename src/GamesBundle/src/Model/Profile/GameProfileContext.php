<?php

namespace App\GamesBundle\Model\Profile;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;

/**
 * Everything needed to read the data of one game profile, built by its provider (createContext()).
 *
 * Sections test the capabilities of the game service with instanceof (HasStaminaInterface...). The account data is
 * null for a provider without API data (Perfect World): no section that reads the game API supports it.
 * $fresh asks every read to skip the caches (notes, endgames...), e.g. for a Refresh button.
 */
final readonly class GameProfileContext
{
    public function __construct(
        public GameProfileInterface $profile,
        public GameProfileRef $ref,
        public GameDescriptor $game,
        public object $gameService,
        public ?RuntimeAccountDataInterface $accountData,
        public bool $fresh = false,
    ) {
    }
}
