<?php

namespace App\HoyoverseBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;

/**
 * Current/max realm currency and the time until it is full, whatever the threshold of the profile.
 */
class RealmCurrencyPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesRealm $realm, string $title, ?string $description = null): UniversalEmbed
    {
        $now = $this->embedFactory->now();
        // Cached notes whose full instant has passed: full by now, as the compact view says
        $current = $realm->hasFilledUpBy($now) ? $realm->getMaxCoin() : $realm->getCurrentCoin();
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField('Realm Currency', "{$current}/{$realm->getMaxCoin()}", icon: '💰', inline: true);

        // Counted from the full instant when known, so cached notes do not show a stale time; no field when unknown
        $secondsUntilFull = $realm->getSecondsUntilFull($now);
        if ($secondsUntilFull !== null) {
            $embed->addField('Recovery Time', $this->durationFormatter->format($secondsUntilFull), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
