<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;

/**
 * One field per daily activity, done or not.
 */
class DailiesPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesDailies $dailies, string $title, ?string $description = null): UniversalEmbed
    {
        $embed = $this->embedFactory->create($profile, $title, $description);
        foreach ($dailies as $daily) {
            $embed->addField($daily->getName(), $daily->getFormattedOutput(), icon: '📅', inline: true);
        }

        return $embed;
    }
}
