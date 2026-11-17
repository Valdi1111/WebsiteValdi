<?php

namespace App\HoyoverseBundle\Model\Game;

interface HasStokenPullImportInterface extends HasPullTrackerInterface
{
    public function getAuthkeyGameBiz(): string;

    public function getAuthkeyAppId(): string;
}
