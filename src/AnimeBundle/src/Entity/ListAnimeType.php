<?php

namespace App\AnimeBundle\Entity;

enum ListAnimeType: string
{
    case unknown = 'unknown';
    case tv = 'tv';
    case tv_special = 'tv_special';
    case ova = 'ova';
    case movie = 'movie';
    case special = 'special';
    case ona = 'ona';
    case music = 'music';
    case pv = 'pv';
}
