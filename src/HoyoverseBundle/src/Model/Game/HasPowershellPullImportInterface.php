<?php

declare(strict_types=1);

namespace App\HoyoverseBundle\Model\Game;

interface HasPowershellPullImportInterface extends HasPullTrackerInterface
{
    public function getPowershellPullConfig(): PowershellPullConfig;
}
