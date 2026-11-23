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
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField('Realm Currency', "{$realm->getCurrentCoin()}/{$realm->getMaxCoin()}", icon: '💰', inline: true);

        // Unknown recovery time: no field rather than a made-up one
        if ($realm->getCoinRecoveryTime() !== null) {
            $embed->addField('Recovery Time', $this->durationFormatter->format($realm->getCoinRecoveryTime()), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
