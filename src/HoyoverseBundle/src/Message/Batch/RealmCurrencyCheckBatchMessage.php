<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This cron will check your Tea Pot Realm currency and notify you if it's full.
 */
#[AsMessage('hoyoverse')]
class RealmCurrencyCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'realmCurrencyCheck';
    }
}