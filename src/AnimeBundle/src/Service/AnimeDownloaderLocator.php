<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\UnhandledWebsiteException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
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
        #[AutowireLocator(services: 'anime.downloader', indexAttribute: 'key')]
        private ServiceCollectionInterface $locator,
    ) {
    }

    /**
     * Resolve the provider supporting the given download request URL
     *
     * @param EpisodeDownloadRequest $downloadReq
     * @return AnimeDownloaderInterface
     * @throws UnhandledWebsiteException if no service has been found for the given download request
     */
    public function getService(EpisodeDownloadRequest $downloadReq): AnimeDownloaderInterface
    {
        $url = $downloadReq->getUrl();

        /** @var AnimeDownloaderInterface $service */
        foreach ($this->locator as $service) {
            if ($service->supports($url)) {
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
