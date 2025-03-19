<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

readonly class AnimeDownloaderLocator
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

}