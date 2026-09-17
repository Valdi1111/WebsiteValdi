<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This will run the Traveling Mimo automation for supported games (Star Rail, ZZZ) - completing tasks, claiming rewards, and exchanging for premium currency.
 */
#[AsMessage('hoyoverse')]
class MimoCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'mimoCheck';
    }
}