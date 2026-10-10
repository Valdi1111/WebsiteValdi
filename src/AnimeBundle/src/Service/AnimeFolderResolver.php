<?php

namespace App\AnimeBundle\Service;

use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use App\AnimeBundle\Service\Tracker\AniListService;
use App\AnimeBundle\Service\Tracker\MyAnimeListService;
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
     * Resolve the target SeasonFolder entity for given anime identifiers
     */
    public function resolveSeasonFolder(?int $malId = null, ?int $alId = null): ?SeasonFolder
    {
        // 1. Try resolving using MyAnimeList ID
        if ($malId !== null) {
            $folder = $this->seasonFolderRepository->findOneBy([
                'id' => $malId,
                'provider' => MyAnimeListService::TRACKER_NAME,
            ]);
            if ($folder !== null) {
                return $folder;
            }
        }

        // 2. Try resolving using AniList ID
        if ($alId !== null) {
            $folder = $this->seasonFolderRepository->findOneBy([
                'id' => $alId,
                'provider' => AniListService::TRACKER_NAME,
            ]);
            if ($folder !== null) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Resolve the target folder path
     */
    public function resolveFolder(?int $malId = null, ?int $alId = null): string
    {
        $seasonFolder = $this->resolveSeasonFolder($malId, $alId);

        return $seasonFolder?->getFolder() ?? $this->tempFolder;
    }

    public function getFallbackFolder(): string
    {
        return $this->tempFolder;
    }
}
