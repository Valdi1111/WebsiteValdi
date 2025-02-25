<?php

namespace App\AnimeBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('anime_episode_download')]
readonly class EpisodeDownloadNotification
{
    public function __construct(private int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}