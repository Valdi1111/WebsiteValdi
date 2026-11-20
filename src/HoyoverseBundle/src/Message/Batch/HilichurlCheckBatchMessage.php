<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This will run the Hilichurl Machine Workshop automation for Genshin Impact - completing tasks, claiming rewards, and exchanging for Primogems.
 */
#[AsMessage('hoyoverse')]
class HilichurlCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'hilichurlCheck';
    }
}