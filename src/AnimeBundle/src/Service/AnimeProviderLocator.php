<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\UnsupportedWebsiteException;
use App\AnimeBundle\Exception\UnsupportedProviderException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Service\Provider\AnimeProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;
use Traversable;

/**
 * @implements ServiceCollectionInterface<AnimeProviderInterface>
 */
readonly class AnimeProviderLocator implements ServiceCollectionInterface
{
    /**
     * @param ServiceCollectionInterface<AnimeProviderInterface> $providers
     */
    public function __construct(
        #[AutowireLocator(services: 'anime.provider', indexAttribute: 'key')]
        private ServiceCollectionInterface $providers,
    )
    {
    }

    /**
     * Resolve the provider supporting the given download request URL
     *
     * @param EpisodeDownloadRequest $downloadReq
     * @return AnimeProviderInterface
     * @throws UnsupportedWebsiteException if no service has been found for the given download request
     */
    public function getService(EpisodeDownloadRequest $downloadReq): AnimeProviderInterface
    {
        $url = $downloadReq->getUrl();

        /** @var AnimeProviderInterface $service */
        foreach ($this->providers as $service) {
            if ($service->supports($url)) {
                return $service;
            }
        }

        throw new UnsupportedWebsiteException();
    }

    public function get(string $id): AnimeProviderInterface
    {
        if (!$this->providers->has($id)) {
            throw new UnsupportedProviderException($id);
        }

        return $this->providers->get($id);
    }

    public function has(string $id): bool
    {
        return $this->providers->has($id);
    }

    public function getIterator(): Traversable
    {
        return $this->providers->getIterator();
    }

    public function count(): int
    {
        return $this->providers->count();
    }

    public function getProvidedServices(): array
    {
        return $this->providers->getProvidedServices();
    }
}
