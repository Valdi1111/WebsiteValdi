<?php

namespace App\HoyoverseBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;

/**
 * Expeditions completed out of those dispatched, and the time until the last one is back while some are still out.
 */
class ExpeditionsPresenter
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
    ) {
    }

    public function present(GameProfileInterface $profile, GameNotesExpeditions $expeditions, string $title, ?string $description = null): UniversalEmbed
    {
        $now = $this->embedFactory->now();
        // Cached notes whose last return has passed: all back by now, as the compact view says
        $done = $expeditions->haveAllReturnedBy($now) ? $expeditions->count() : $expeditions->countDone();
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField(
                'Expeditions',
                $expeditions->isEmpty() ? 'None dispatched' : "{$done}/{$expeditions->count()} completed",
                icon: '🧭',
                inline: true
            );

        $completionTime = $expeditions->getSecondsUntilAllBack($now);
        if ($completionTime !== null) {
            $embed->addField('Completion Time', $this->durationFormatter->format($completionTime), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
