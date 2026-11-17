<?php

declare(strict_types=1);

namespace App\HoyoverseBundle\Model\Game;

class PowershellPullConfig
{
    /**
     * @param list<string> $localLowFolders   Subfolders under AppData\LocalLow where Unity logs live.
     * @param list<string> $logFiles          Log filenames to inspect for the game installation path.
     * @param string       $dataFolderPattern Regex fragment matching the *_Data directory name.
     * @param string       $urlRegex          Regex matching valid getGachaLog URLs for this game.
     */
    public function __construct(
        private array $localLowFolders = [],
        private array $logFiles = [],
        private string $dataFolderPattern = '',
        private string $urlRegex = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function getLocalLowFolders(): array
    {
        return $this->localLowFolders;
    }

    /**
     * @param list<string> $localLowFolders
     */
    public function setLocalLowFolders(array $localLowFolders): static
    {
        $this->localLowFolders = $localLowFolders;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLogFiles(): array
    {
        return $this->logFiles;
    }

    /**
     * @param list<string> $logFiles
     */
    public function setLogFiles(array $logFiles): static
    {
        $this->logFiles = $logFiles;

        return $this;
    }

    public function getDataFolderPattern(): string
    {
        return $this->dataFolderPattern;
    }

    public function setDataFolderPattern(string $dataFolderPattern): static
    {
        $this->dataFolderPattern = $dataFolderPattern;

        return $this;
    }

    public function getUrlRegex(): string
    {
        return $this->urlRegex;
    }

    public function setUrlRegex(string $urlRegex): static
    {
        $this->urlRegex = $urlRegex;

        return $this;
    }
}
