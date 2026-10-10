<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnsupportedTrackerException;
use App\AnimeBundle\Model\ScrapedEpisode;
use App\AnimeBundle\Model\TrackerIdentifier;
use App\AnimeBundle\Service\Tracker\AnimeTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;
use Traversable;

/**
 * @implements ServiceCollectionInterface<AnimeTrackerInterface>
 */
readonly class AnimeTrackerLocator implements ServiceCollectionInterface
{
    /**
     * @param ServiceCollectionInterface<AnimeTrackerInterface> $trackers
     */
    public function __construct(
        #[AutowireLocator(services: 'anime.tracker', indexAttribute: 'key')]
        private ServiceCollectionInterface $trackers,
    )
    {
    }

    /**
     * Ensure anime exists in user list across supported trackers.
     * Returns the first matching tracker identifier found in cache.
     *
     * @throws CacheAnimeNotFoundException
     */
    public function ensureAnimeInList(ScrapedEpisode $episode): TrackerIdentifier
    {
        $identifiers = $episode->getTrackers();

        foreach ($identifiers as $identifier) {
            if (!$this->has($identifier->getTracker())) {
                continue;
            }

            $tracker = $this->get($identifier->getTracker());
            if ($tracker->existsInAnimeCache($identifier->getTrackerId())) {
                // Match found in at least one tracker cache
                return $identifier;
            }
        }

        throw new CacheAnimeNotFoundException(array_values($identifiers));
    }

    public function get(string $id): AnimeTrackerInterface
    {
        if (!$this->trackers->has($id)) {
            throw new UnsupportedTrackerException($id);
        }

        return $this->trackers->get($id);
    }

    public function has(string $id): bool
    {
        return $this->trackers->has($id);
    }

    public function getIterator(): Traversable
    {
        return $this->trackers->getIterator();
    }

    public function count(): int
    {
        return $this->trackers->count();
    }

    public function getProvidedServices(): array
    {
        return $this->trackers->getProvidedServices();
    }
}
