<?php

namespace App\AnimeBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias('anime.downloader.animeunity')]
#[AsAlias('App\AnimeBundle\Service\AnimeDownloaderInterface $animeUnityDownloader')]
#[AutoconfigureTag('anime.downloader', attributes: ['config' => 'anime.animeunity'])]
readonly class AnimeUnityService implements AnimeDownloaderInterface
{

    public function __construct(
        private EntityManagerInterface                       $entityManager,
        private HttpClientInterface                          $animeAnimeunityClient,
        #[Autowire('%anime.temp_folder%')] private string    $tempFolder,
        #[Autowire('%anime.animeunity.url%')] private string $websiteUrl)
    {
    }

    /**
     * @inheritDoc
     */
    public function createEpisodeDownloads(string $urlPath, bool $allEpisodes = false, bool $filter = true, bool $save = true): array
    {
        // TODO: Implement createEpisodeDownloads() method.
    }

    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }
}