<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Entity\EpisodeDownloadAttempt;
use App\AnimeBundle\Entity\EpisodeRelease;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\SiteUnavailableException;
use App\AnimeBundle\Message\EpisodeDownloadMessage;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Model\EpisodeDownloadState;
use App\AnimeBundle\Model\TrackerIdentifier;
use App\AnimeBundle\Repository\EpisodeReleaseRepository;
use App\AnimeBundle\Service\Provider\AnimeProviderInterface;
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
        private AnimeProviderLocator     $providerLocator,
        private AnimeFolderResolver      $folderResolver,
        private AnimeTrackerLocator      $trackerLocator,
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
    public function processDownloadRequest(EpisodeDownloadRequest $downloadReq, ?AnimeProviderInterface $provider = null): array
    {
        $provider ??= $this->providerLocator->getService($downloadReq);
        $scrapedDtos = $provider->scrapeEpisodes($downloadReq);

        $episodes = [];
        $attemptsToDispatch = [];

        foreach ($scrapedDtos as $dto) {
            // Resolve tracker: check user cache or fallback to first available identifier
            $trackerIdentifier = $downloadReq->isFilter()
                ? $this->trackerLocator->ensureAnimeInList($dto)
                : TrackerIdentifier::firstFromScrapedEpisode($dto);

            // Resolve target folder and possible season offset
            $seasonFolder = $this->folderResolver->resolveSeasonFolder($trackerIdentifier);
            $folder = $seasonFolder?->getFolder() ?? $this->folderResolver->getFallbackFolder();
            $offset = $seasonFolder?->getEpisodeOffset() ?? 0;

            // 1. Parse raw original numbers (e.g. "7-8" -> [7, 8], "7.5" -> [7.5])
            $parsedNumbers = $this->episodeCalculator->parseEpisodes($dto->getEpisodeNumber());

            // 2. Apply folder offset if configured
            $adjustedNumbers = $this->episodeCalculator->applyOffset($parsedNumbers, $offset);

            // 3. Rebuild formatted episode string
            $finalEpisodeString = !empty($adjustedNumbers)
                ? $this->episodeCalculator->formatEpisodeString($adjustedNumbers)
                : $dto->getEpisodeNumber();

            // 4. Compute destination filename with updated episode numbers
            $finalFilename = $this->episodeCalculator->computeFilename(
                $dto->getFilename(),
                $dto->getEpisodeNumber(),
                $finalEpisodeString
            );

            // Initialize main episode download entity
            $episode = new EpisodeDownload()
                ->setProvider($dto->getProvider())
                ->setEpisodeUrl($dto->getEpisodeUrl())
                ->setOriginalEpisode($dto->getEpisodeNumber())
                ->setEpisode($finalEpisodeString)
                ->setEpisodes($adjustedNumbers)
                ->setFolder($folder)
                ->setTracker($trackerIdentifier?->getTracker())
                ->setTrackerId($trackerIdentifier?->getTrackerId())
                ->setMalId($dto->getMalId())
                ->setAlId($dto->getAlId())
                ->setDownloadUrl($dto->getDownloadUrl())
                ->setOriginalFile($dto->getFilename())
                ->setFile($finalFilename)
                ->setState(EpisodeDownloadState::created);

            // Instantiate and attach initial execution attempt
            $initialAttempt = new EpisodeDownloadAttempt()
                ->setState(EpisodeDownloadState::created);
            $episode->addEpisodeDownloadAttempt($initialAttempt);

            if ($downloadReq->isSave()) {
                $this->entityManager->persist($episode);
                $attemptsToDispatch[] = $initialAttempt;
            }

            $episodes[] = $episode;
        }

        if ($downloadReq->isSave()) {
            $this->entityManager->flush();

            $stamps = [];
            if ($downloadReq->getDelay() > 0) {
                $stamps[] = new DelayStamp($downloadReq->getDelay() * 1000);
            }

            foreach ($attemptsToDispatch as $attempt) {
                $this->bus->dispatch(new EpisodeDownloadMessage($attempt->getId()), $stamps);
            }
        }

        return $episodes;
    }

    /**
     * Check and enqueue newly released episodes for a given service provider
     *
     * @return EpisodeDownload[]
     */
    public function checkNewEpisodes(string $providerName): array
    {
        $provider = $this->providerLocator->get($providerName);

        try {
            $urlPaths = $provider->fetchLatestEpisodeUrls();
        } catch (SiteUnavailableException $e) {
            $this->logger->warning("Skipping {provider} check: {message}", [
                'provider' => $providerName,
                'message' => $e->getMessage(),
            ]);
            return [];
        }

        $episodes = [];
        $attemptsToDispatch = [];

        foreach ($urlPaths as $urlPath) {
            // Check if episode has already been released
            $release = $this->releaseRepository->findOneBy([
                'provider' => $providerName,
                'episodeUrl' => $urlPath,
            ]);
            if ($release) {
                continue;
            }

            // Add release to database
            $release = new EpisodeRelease()
                ->setEpisodeUrl($urlPath)
                ->setProvider($providerName);
            $this->entityManager->persist($release);

            // Create download request
            // TODO add option to download missed episodes
            $downloadReq = new EpisodeDownloadRequest()
                ->setUrl($provider->getWebsiteUrl() . $urlPath)
                ->setSave(false);

            // Create episode downloads
            try {
                $scrapedEpisodes = $this->processDownloadRequest($downloadReq, $provider);
                foreach ($scrapedEpisodes as $episodeLocal) {
                    $this->entityManager->persist($episodeLocal);
                    $episodes[] = $episodeLocal;

                    // Collect the created attempt to dispatch once saved
                    $lastAttempt = $episodeLocal->getLastAttempt();
                    if ($lastAttempt) {
                        $attemptsToDispatch[] = $lastAttempt;
                    }
                }
            } catch (CacheAnimeNotFoundException) {
                continue;
            }
        }

        $this->entityManager->flush();

        foreach ($attemptsToDispatch as $attempt) {
            $this->bus->dispatch(new EpisodeDownloadMessage($attempt->getId()));
        }

        return $episodes;
    }

    /**
     * Refresh URL, reset state, delete incomplete file, record a new attempt, and re-queue download.
     */
    public function retryDownload(EpisodeDownload $download): void
    {
        // 1. Fetch provider and extract fresh CDN download URL (tokens/signatures might have expired)
        $provider = $this->providerLocator->get($download->getProvider());
        $newUrl = $provider->extractDownloadUrl($download->getEpisodeUrl());
        $download->setDownloadUrl($newUrl);

        // 2. Reset primary download state and execution timestamps
        $download->setState(EpisodeDownloadState::created)
            ->setStarted(null)
            ->setCompleted(null);

        // 3. Create and attach a new attempt instance for this retry cycle
        $newAttempt = new EpisodeDownloadAttempt()
            ->setState(EpisodeDownloadState::created);
        $download->addEpisodeDownloadAttempt($newAttempt);

        $this->entityManager->flush();

        // 4. Remove any partially downloaded or corrupted file from storage
        $filePath = Path::join($download->getFolder(), $download->getFile());
        if ($this->animeStorage->fileExists($filePath)) {
            $this->animeStorage->delete($filePath);
        }

        // 5. Dispatch message with the new attempt ID to Messenger queue
        $this->bus->dispatch(new EpisodeDownloadMessage($newAttempt->getId()));
    }
}
