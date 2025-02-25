<?php

namespace App\AnimeBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('core_async')]
readonly class MangaCacheRefreshNotification
{
    public function __construct()
    {
    }

}