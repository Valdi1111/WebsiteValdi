<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Service\DurationFormatter;
use Psr\Log\LoggerInterface;

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
    public const string PROFILE_UNAVAILABLE = 'The data of this profile could not be read right now.';

    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly DurationFormatter $durationFormatter,
        private readonly ?LoggerInterface $logger = null,
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
            $line = $result->isSuccessful() ? $this->summarize($status, $result) : null;
            if ($line !== null) {
                $embed->addField($line->label, ($line->attention ? self::ATTENTION . ' ' : '') . $line->value, icon: $line->icon);
            } else {
                $embed->addField($section->getLabel(), '⚠️ ' . self::UNAVAILABLE, icon: $section->getIcon());
            }
        }

        return $embed;
    }

    /**
     * The line of a section read fine, or null when it cannot be laid out (unexpected data): that section then shows the
     * generic line, and the other sections and profiles still do.
     */
    private function summarize(GameProfileStatus $status, SectionResult $result): ?SummaryLine
    {
        try {
            return $result->section->summarize($status->profile, $result->data);
        } catch (\Throwable $e) {
            // Class and code only, as the collector logs
            $this->logger?->warning('A section of the game profile could not be summarized: {error}', [
                'profile' => (string) $status->ref,
                'section' => $result->section->getKey(),
                'error' => $e::class,
                'code' => $e->getCode(),
            ]);

            return null;
        }
    }

    private function describe(GameProfileStatus $status): ?string
    {
        $lines = [];
        if (!$status->accountHealth->ok) {
            $lines[] = '⚠️ ' . $status->accountHealth->problem;
        }
        if ($status->error !== null) {
            $lines[] = '⚠️ ' . self::PROFILE_UNAVAILABLE;
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
