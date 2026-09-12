<?php

namespace App\HoyoverseBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This cron will check your Tea Pot Realm currency and notify you if it's full.
 */
#[AsMessage('hoyoverse')]
class RealmCurrencyMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{

    public function getFeatureFlagField(): string
    {
        return 'realmCurrencyCheck';
    }

}