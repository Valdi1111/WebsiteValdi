<?php

namespace App\HoyoverseBundle\Model\Game;

interface HasPowershellPullImportInterface extends HasPullTrackerInterface
{
    /**
     * Folder name used under AppData/LocalLow/miHoYo or Cognosphere.
     */
    public function getPowershellGameFolderName(): string;
}
