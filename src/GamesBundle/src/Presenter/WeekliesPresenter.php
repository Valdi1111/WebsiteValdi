<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;

/**
 * One field per weekly activity, done or not.
 */
class WeekliesPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesWeeklies $weeklies, string $title, ?string $description = null): UniversalEmbed
    {
        $embed = $this->embedFactory->create($profile, $title, $description);
        foreach ($weeklies as $weekly) {
            $embed->addField($weekly->getName(), $weekly->getFormattedOutput(), icon: '📊', inline: true);
        }

        return $embed;
    }
}
