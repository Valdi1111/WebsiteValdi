<?php

namespace App\AnimeBundle\MessageHandler;

use App\AnimeBundle\Message\MangaCacheRefreshNotification;
use App\AnimeBundle\Service\MyAnimeListService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class MangaCacheRefreshNotificationHandler
{

    public function __construct(private MyAnimeListService $malService)
    {
    }

    public function __invoke(MangaCacheRefreshNotification $message): void
    {
        $this->malService->refreshMangaCache();
    }
}