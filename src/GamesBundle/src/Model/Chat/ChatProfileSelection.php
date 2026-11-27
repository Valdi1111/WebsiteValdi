<?php

namespace App\GamesBundle\Model\Chat;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;

/**
 * The profiles a game chat command shows, from its game and profile options (ChatProfileSelector), with the filter in
 * its canonical form: a button can rebuild exactly the same selection from it, whatever the user typed (alias, UID,
 * nickname).
 */
final readonly class ChatProfileSelection
{
    /**
     * @param list<GameProfileInterface> $profiles in the order of the registry
     * @param list<GameProfileRef>       $refs     one per profile, same order
     */
    public function __construct(
        public ?GameDescriptor $game,
        public ?GameProfileRef $profile,
        public array $profiles,
        public array $refs,
    ) {
    }
}
