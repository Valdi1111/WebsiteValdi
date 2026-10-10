<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnsupportedTrackerException;
use App\AnimeBundle\Model\ScrapedEpisode;
use App\AnimeBundle\Service\Tracker\AnimeTrackerInterface;
use App\AnimeBundle\Service\Tracker\MyAnimeListService;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

readonly class AnimeListChecker
{
    /**
     * @param ServiceCollectionInterface<AnimeTrackerInterface> $trackers
     */
    public function __construct(
        #[AutowireLocator(services: 'anime.tracker', indexAttribute: 'key')]
        private ServiceCollectionInterface $trackers,
    ) {
    }

    /**
     * Verify whether the scraped episode exists in any configured tracker cache
     *
     * @throws CacheAnimeNotFoundException
     */
    public function ensureAnimeInList(ScrapedEpisode $episode): void
    {
        $identifiers = [];
        if ($episode->getMalId() !== null) {
            $identifiers[MyAnimeListService::TRACKER_NAME] = $episode->getMalId();
        }
        if ($episode->getAlId() !== null) {
            $identifiers['anilist'] = $episode->getAlId();
        }

        // If no tracking identifier was scraped from the episode page, skip filtering
        if (empty($identifiers)) {
            return;
        }

        foreach ($identifiers as $trackerKey => $externalId) {
            if ($this->trackers->has($trackerKey)) {
                /** @var AnimeTrackerInterface $tracker */
                $tracker = $this->trackers->get($trackerKey);
                if ($tracker->existsInAnimeCache($externalId)) {
                    // Match found in at least one tracker cache
                    return;
                }
            }
        }

        throw new CacheAnimeNotFoundException($identifiers);
    }

    public function getTracker(string $trackerName): AnimeTrackerInterface
    {
        if (!$this->trackers->has($trackerName)) {
            throw new UnsupportedTrackerException($trackerName);
        }

        return $this->trackers->get($trackerName);
    }

    /**
     * @return ServiceCollectionInterface<AnimeTrackerInterface>
     */
    public function getTrackers(): ServiceCollectionInterface
    {
        return $this->trackers;
    }
}
