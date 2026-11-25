<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Service\DurationFormatter;

/**
 * Compact view of one game profile: the usual header, then one line per section read by GameProfileStatusCollector.
 * Shared by the status commands and the daily summary.
 *
 * A failed section shows a generic line: the error itself can hold URLs or response bodies and stays in the logs.
 * The timestamp is the fetch time of the notes when known, so the age of cached data shows.
 */
class ProfileSummaryPresenter
{
    public const string TITLE = 'Status';
    public const string UNAVAILABLE = 'Unavailable right now';
    public const string ATTENTION = '🔔';

    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
    ) {
    }

    public function present(GameProfileStatus $status): UniversalEmbed
    {
        $embed = $this->embedFactory->create($status->profile, self::TITLE, $this->describe($status));
        if ($status->fetchedAt !== null) {
            $embed->setTimestamp($status->fetchedAt);
        }

        foreach ($status->sections as $result) {
            $section = $result->section;
            if ($result->isSuccessful()) {
                $line = $section->summarize($status->profile, $result->data);
                $embed->addField($line->label, ($line->attention ? self::ATTENTION . ' ' : '') . $line->value, icon: $line->icon);
            } else {
                $embed->addField($section->getLabel(), '⚠️ ' . self::UNAVAILABLE, icon: $section->getIcon());
            }
        }

        return $embed;
    }

    private function describe(GameProfileStatus $status): ?string
    {
        $lines = [];
        if (!$status->accountHealth->ok) {
            $lines[] = '⚠️ ' . $status->accountHealth->problem;
        }
        if ($status->error !== null) {
            $lines[] = '⚠️ The data of this profile could not be read right now.';
        } elseif ($status->sections === []) {
            // Neutral: the game may have data, only not in the sections asked
            $lines[] = 'Nothing to show for this game.';
        }
        if ($status->fetchedAt !== null) {
            $age = $this->embedFactory->now()->getTimestamp() - $status->fetchedAt->getTimestamp();
            if ($age >= 60) {
                $lines[] = sprintf('Data from %s ago.', $this->durationFormatter->format($age, 1));
            }
        }

        return $lines !== [] ? implode("\n", $lines) : null;
    }
}
