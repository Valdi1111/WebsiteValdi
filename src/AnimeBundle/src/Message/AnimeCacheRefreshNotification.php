<?php

namespace App\AnimeBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('core_async')]
readonly class AnimeCacheRefreshNotification
{
    public function __construct()
    {
    }

}