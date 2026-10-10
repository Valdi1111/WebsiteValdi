<?php

namespace App\AnimeBundle\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('anime_episode_download')]
readonly class EpisodeDownloadMessage
{
    public function __construct(private int $attemptId)
    {
    }

    public function getAttemptId(): int
    {
        return $this->attemptId;
    }
}
