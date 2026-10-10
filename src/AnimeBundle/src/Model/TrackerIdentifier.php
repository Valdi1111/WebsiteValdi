<?php

namespace App\AnimeBundle\Model;

use App\AnimeBundle\Service\Tracker\AniListTracker;
use App\AnimeBundle\Service\Tracker\MyAnimeListTracker;

readonly class TrackerIdentifier
{
    public function __construct(
        private string $tracker,
        private int    $trackerId,
    )
    {
    }

    public static function from(string $tracker, int $trackerId): self
    {
        return new self($tracker, $trackerId);
    }

    public static function fromMyAnimeList(int $id): self
    {
        return new self(MyAnimeListTracker::TRACKER_NAME, $id);
    }

    public static function fromAniList(int $id): self
    {
        return new self(AniListTracker::TRACKER_NAME, $id);
    }

    public function getTracker(): string
    {
        return $this->tracker;
    }

    public function getTrackerId(): int
    {
        return $this->trackerId;
    }

    public function is(?string $trackerName): bool
    {
        return $this->tracker === $trackerName;
    }

    public function same(?self $tracker): bool
    {
        if (!$tracker) {
            return false;
        }
        return $this->is($tracker->getTracker()) && $this->getTrackerId() === $tracker->getTrackerId();
    }
}
