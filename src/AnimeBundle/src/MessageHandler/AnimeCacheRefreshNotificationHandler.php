<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Message\AnimeCacheRefreshNotification;
use App\AnimeBundle\Service\MyAnimeListService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AnimeCacheRefreshNotificationHandler
{

    public function __construct(private MyAnimeListService $malService)
    {
    }

    public function __invoke(AnimeCacheRefreshNotification $message): void
    {
        $this->malService->refreshAnimeCache();
    }
}