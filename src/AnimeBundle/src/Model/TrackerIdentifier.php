<?php

namespace App\AnimeBundle\Model;

use App\AnimeBundle\Service\Tracker\AniListTracker;
use App\AnimeBundle\Service\Tracker\MyAnimeListTracker;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

readonly class TrackerIdentifier
{
    public function __construct(
        private string $tracker,
        private int    $trackerId,
    ) {
    }

    /**
     * Extract all available TrackerIdentifier instances from a scraped episode.
     *
     * @return Collection<self>
     */
    public static function allFromScrapedEpisode(ScrapedEpisode $episode): Collection
    {
        $identifiers = [];

        if ($episode->getMalId() !== null) {
            $identifiers[] = new self(MyAnimeListTracker::TRACKER_NAME, $episode->getMalId());
        }

        if ($episode->getAlId() !== null) {
            $identifiers[] = new self(AniListTracker::TRACKER_NAME, $episode->getAlId());
        }

        return new ArrayCollection($identifiers);
    }

    /**
     * Pick the first available TrackerIdentifier from a scraped episode, or null if empty.
     */
    public static function firstFromScrapedEpisode(ScrapedEpisode $episode): ?self
    {
        return self::allFromScrapedEpisode($episode)->first() ?: null;
    }

    public function getTracker(): string
    {
        return $this->tracker;
    }

    public function getTrackerId(): int
    {
        return $this->trackerId;
    }

}
