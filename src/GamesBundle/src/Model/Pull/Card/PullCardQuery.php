<?php

namespace App\GamesBundle\Model\Pull\Card;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Options of the shareable pulls card endpoint (query string).
 */
final readonly class PullCardQuery
{
    /**
     * @param ?string $banner key of the banner group, null for every banner of the profile
     * @param bool    $all    every S pull instead of the newest ones
     * @param bool    $player whether the nickname and the UID appear on the card
     */
    public function __construct(
        #[Assert\Length(min: 1, max: 64)]
        public ?string $banner = null,
        public bool $all = false,
        public bool $player = false,
    ) {
    }
}
