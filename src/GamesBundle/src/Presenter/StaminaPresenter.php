<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Service\DurationFormatter;

/**
 * Current/max stamina and the time until it is full, whatever the threshold of the profile.
 */
class StaminaPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesStamina $stamina, string $title, ?string $description = null): UniversalEmbed
    {
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField('Stamina', "{$stamina->getCurrentStamina()}/{$stamina->getMaxStamina()}", icon: '🔋', inline: true);

        // Unknown recovery time: no field rather than a made-up one
        if ($stamina->getStaminaRecoveryTime() !== null) {
            $embed->addField('Recovery Time', $this->durationFormatter->format($stamina->getStaminaRecoveryTime()), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
