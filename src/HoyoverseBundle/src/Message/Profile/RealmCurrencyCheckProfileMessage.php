<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * This cron will check your Tea Pot Realm currency and notify you if it's full.
 */
#[AsMessage('hoyoverse')]
class RealmCurrencyCheckProfileMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly int $gameProfileId
    )
    {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }
}