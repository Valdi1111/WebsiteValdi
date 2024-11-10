<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Exception\UnhandledWebsiteException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

readonly class AnimeDownloaderLocator
{
    private array $urlSplits;

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

    public function parseUrl(string $url): void
    {
        $this->urlSplits = parse_url($url);
    }

    public function getBaseUrl(): string
    {
        return "{$this->urlSplits['scheme']}://{$this->urlSplits['host']}";
    }

    public function getUrlPath(): string
    {
        return $this->urlSplits['path'];
    }

    public function getService(): AnimeDownloaderInterface
    {
        $baseUrl = $this->getBaseUrl();
        // iterate through all services of the locator
        foreach ($this->locator as $serviceId => $service) {
            // if
            if ($this->parameterBag->get("anime.$serviceId.url") === $baseUrl) {
                return $service;
            }
        }
        throw new UnhandledWebsiteException();
    }

}