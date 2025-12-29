<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;
use Traversable;

readonly class AnimeDownloaderLocator implements ServiceCollectionInterface
{

    /**
     * @param ServiceCollectionInterface<AnimeDownloaderInterface> $locator
     */
    public function __construct(
        #[AutowireLocator(services: 'anime.downloader', defaultIndexMethod: 'getServiceName')]
        private ServiceCollectionInterface $locator,
        private ParameterBagInterface      $parameterBag,
    )
    {
    }

    /**
     * @param EpisodeDownloadRequest $downloadReq
     * @return AnimeDownloaderInterface
     * @throws UnhandledWebsiteException if no service has been found for the given download request
     */
    public function getService(EpisodeDownloadRequest $downloadReq): AnimeDownloaderInterface
    {
        // iterate through all services of the locator
        foreach ($this->locator as $serviceId => $service) {
            if ($this->parameterBag->has("anime.$serviceId.url_regex") &&
                preg_match($this->parameterBag->get("anime.$serviceId.url_regex"), $downloadReq->getUrl())) {
                return $service;
            }
        }
        throw new UnhandledWebsiteException();
    }

    public function get(string $id): AnimeDownloaderInterface
    {
        return $this->locator->get($id);
    }

    public function has(string $id): bool
    {
        return $this->locator->has($id);
    }

    public function getIterator(): Traversable
    {
        return $this->locator->getIterator();
    }

    public function count(): int
    {
        return $this->locator->count();
    }

    public function getProvidedServices(): array
    {
        return $this->locator->getProvidedServices();
    }
}