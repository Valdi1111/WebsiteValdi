<?php

namespace App\AnimeBundle\Entity;

enum EpisodeDownloadState: string
{
    case created = 'created';
    case error_starting = 'error_starting';
    case downloading = 'downloading';
    case error_downloading = 'error_downloading';
    case completed = 'completed';
}
