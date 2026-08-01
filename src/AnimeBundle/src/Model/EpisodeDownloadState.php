<?php

namespace App\AnimeBundle\Model;

enum EpisodeDownloadState: string
{
    case created = 'created';
    case error_starting = 'error_starting';
    case downloading = 'downloading';
    case error_downloading = 'error_downloading';
    case completed = 'completed';
}
