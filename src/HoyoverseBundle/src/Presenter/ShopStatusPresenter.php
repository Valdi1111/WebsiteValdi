<?php

namespace App\HoyoverseBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;

/**
 * State of the shop (Zenless Zone Zero video store): Closed, Open or Finished.
 */
class ShopStatusPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesStateMetric $shopStatus, string $title, ?string $description = null): UniversalEmbed
    {
        $embed = $this->embedFactory->create($profile, $title, $description);

        // Unknown state: no field rather than a made-up one
        if ($shopStatus->getCurrentValue() !== null) {
            $embed->addField($shopStatus->getName() ?? 'Shop Status', $shopStatus->getFormattedOutput(), icon: '📼', inline: true);
        }

        return $embed;
    }
}
