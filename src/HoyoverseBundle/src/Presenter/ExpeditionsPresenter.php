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
        $embed = $this->embedFactory->create($profile, $title, $description)
            ->addField(
                'Expeditions',
                $expeditions->isEmpty() ? 'None dispatched' : "{$expeditions->countDone()}/{$expeditions->count()} completed",
                icon: '🧭',
                inline: true
            );

        $completionTime = $expeditions->getSecondsUntilAllBack($this->embedFactory->now());
        if ($completionTime !== null) {
            $embed->addField('Completion Time', $this->durationFormatter->format($completionTime), icon: '🕒', inline: true);
        }

        return $embed;
    }
}
