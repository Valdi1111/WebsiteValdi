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
        $now = $this->embedFactory->now();
        // Cached notes whose full instant has passed: full by now, as the compact view says
        $current = $stamina->hasFilledUpBy($now) ? $stamina->getMaxStamina() : $stamina->getCurrentStamina();
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField('Stamina', "{$current}/{$stamina->getMaxStamina()}", icon: '🔋', inline: true);

        // Counted from the full instant when known, so cached notes do not show a stale time; no field when unknown
        $secondsUntilFull = $stamina->getSecondsUntilFull($now);
        if ($secondsUntilFull !== null) {
            $embed->addField('Recovery Time', $this->durationFormatter->format($secondsUntilFull), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
