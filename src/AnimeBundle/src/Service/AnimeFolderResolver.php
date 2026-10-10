<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Model\TrackerIdentifier;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class AnimeFolderResolver
{
    public function __construct(
        private SeasonFolderRepository $seasonFolderRepository,
        #[Autowire(param: 'anime.temp_folder')]
        private string                 $tempFolder,
    ) {
    }

    /**
     * Resolve the target SeasonFolder entity for a given tracker identifier
     */
    public function resolveSeasonFolder(?TrackerIdentifier $trackerIdentifier): ?SeasonFolder
    {
        if ($trackerIdentifier === null) {
            return null;
        }

        return $this->seasonFolderRepository->findOneBy([
            'id' => $trackerIdentifier->getTrackerId(),
            'tracker' => $trackerIdentifier->getTracker(),
        ]);
    }

    /**
     * Resolve the target folder path
     */
    public function resolveFolder(?TrackerIdentifier $trackerIdentifier): string
    {
        $seasonFolder = $this->resolveSeasonFolder($trackerIdentifier);

        return $seasonFolder?->getFolder() ?? $this->getFallbackFolder();
    }

    public function getFallbackFolder(): string
    {
        return $this->tempFolder;
    }
}
