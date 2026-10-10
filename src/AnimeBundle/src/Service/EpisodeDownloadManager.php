<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeRelease;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\SiteUnavailableException;
use App\AnimeBundle\Message\EpisodeDownloadMessage;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Repository\EpisodeReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

readonly class EpisodeDownloadManager
{
    public function __construct(
        private EntityManagerInterface   $entityManager,
        private MessageBusInterface      $bus,
        private AnimeDownloaderLocator   $locator,
        private AnimeFolderResolver      $folderResolver,
        private AnimeListChecker         $listChecker,
        private EpisodeNumberCalculator  $episodeCalculator,
        private EpisodeReleaseRepository $releaseRepository,
        private AnimeStorage             $animeStorage,
        #[Target('anime.episode_downloader')]
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * Scrape, validate, and optionally persist and dispatch episode download requests
     *
     * @return EpisodeDownload[]
     */
    public function processDownloadRequest(EpisodeDownloadRequest $downloadReq, ?AnimeDownloaderInterface $provider = null): array
    {
        $provider ??= $this->locator->getService($downloadReq);
        $scrapedDtos = $provider->scrapeEpisodes($downloadReq);

        $episodes = [];
        foreach ($scrapedDtos as $dto) {
            if ($downloadReq->isFilter()) {
                $this->listChecker->ensureAnimeInList($dto);
            }

            $seasonFolder = $this->folderResolver->resolveSeasonFolder($dto->getMalId(), $dto->getAlId());
            $folder = $seasonFolder?->getFolder() ?? $this->folderResolver->getFallbackFolder();
            $offset = $seasonFolder?->getEpisodeOffset() ?? 0;

            // 1. Parse raw original numbers (e.g. "7-8" -> [7.0, 8.0])
            $parsedNumbers = $this->episodeCalculator->parseEpisodes($dto->getEpisodeNumber());

            // 2. Apply folder offset (e.g. +12 -> [19.0, 20.0])
            $adjustedNumbers = $this->episodeCalculator->applyOffset($parsedNumbers, $offset);

            // 3. Format adjusted episode string (e.g. "19-20")
            $finalEpisodeString = !empty($adjustedNumbers)
                ? $this->episodeCalculator->formatEpisodeString($adjustedNumbers)
                : $dto->getEpisodeNumber();

            // 4. Compute adjusted filename replacing old numbering with new
            $finalFilename = $this->episodeCalculator->computeFilename(
                $dto->getFilename(),
                $dto->getEpisodeNumber(),
                $finalEpisodeString
            );

            $episode = new EpisodeDownload()
                ->setServiceName($dto->getServiceName())
                ->setEpisodeUrl($dto->getEpisodeUrl())
                ->setOriginalEpisode($dto->getEpisodeNumber())
                ->setEpisode($finalEpisodeString)
                ->setEpisodes($adjustedNumbers)
                ->setFolder($folder)
                ->setMalId($dto->getMalId())
                ->setAlId($dto->getAlId())
                ->setDownloadUrl($dto->getDownloadUrl())
                ->setOriginalFile($dto->getFilename())
                ->setFile($finalFilename);

            if ($downloadReq->isSave()) {
                $this->entityManager->persist($episode);
            }
            $episodes[] = $episode;
        }

        if ($downloadReq->isSave()) {
            $this->entityManager->flush();

            $stamps = [];
            if ($downloadReq->getDelay() > 0) {
                $stamps[] = new DelayStamp($downloadReq->getDelay() * 1000);
            }

            foreach ($episodes as $episode) {
                $this->bus->dispatch(new EpisodeDownloadMessage($episode->getId()), $stamps);
            }
        }

        return $episodes;
    }

    /**
     * Check and enqueue newly released episodes for a given service provider
     *
     * @return EpisodeDownload[]
     */
    public function checkNewEpisodes(string $serviceName): array
    {
        $downloader = $this->locator->get($serviceName);

        try {
            $urlPaths = $downloader->fetchLatestEpisodeUrls();
        } catch (SiteUnavailableException $e) {
            $this->logger->warning("Skipping {service} check: {message}", [
                'service' => $serviceName,
                'message' => $e->getMessage(),
            ]);
            return [];
        }

        $episodes = [];
        foreach ($urlPaths as $urlPath) {
            // Check if episode has already been released
            $release = $this->releaseRepository->findOneBy([
                'serviceName' => $serviceName,
                'episodeUrl' => $urlPath,
            ]);
            if ($release) {
                continue;
            }

            // Add release to database
            $release = new EpisodeRelease()
                ->setEpisodeUrl($urlPath)
                ->setServiceName($serviceName);
            $this->entityManager->persist($release);

            // Create download request
            // TODO add option to download missed episodes
            $downloadReq = new EpisodeDownloadRequest()
                ->setUrl($downloader->getWebsiteUrl() . $urlPath)
                ->setSave(false);

            // Create episode downloads
            try {
                $scrapedEpisodes = $this->processDownloadRequest($downloadReq, $downloader);
                foreach ($scrapedEpisodes as $episodeLocal) {
                    $this->entityManager->persist($episodeLocal);
                    $episodes[] = $episodeLocal;
                }
            } catch (CacheAnimeNotFoundException) {
                continue;
            }
        }

        $this->entityManager->flush();

        foreach ($episodes as $episode) {
            $this->bus->dispatch(new EpisodeDownloadMessage($episode->getId()));
        }

        return $episodes;
    }

    /**
     * Refresh URL, reset state, delete existing file and re-queue download
     */
    public function retryDownload(EpisodeDownload $download): void
    {
        $provider = $this->locator->get($download->getServiceName());
        $newUrl = $provider->extractDownloadUrl($download->getEpisodeUrl());
        $download->setDownloadUrl($newUrl);

        $download->setState(EpisodeDownloadState::created)
            ->setStarted(null)
            ->setCompleted(null);

        $this->entityManager->flush();

        $filePath = Path::join($download->getFolder(), $download->getFile());
        if ($this->animeStorage->fileExists($filePath)) {
            $this->animeStorage->delete($filePath);
        }

        $this->bus->dispatch(new EpisodeDownloadMessage($download->getId()));
    }
}
