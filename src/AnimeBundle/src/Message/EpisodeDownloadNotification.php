<?php

namespace App\AnimeBundle\Message;

class EpisodeDownloadNotification
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}