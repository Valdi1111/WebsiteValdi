<?php

namespace App\AnimeBundle\Entity;

enum ListMangaStatus: string
{
    case reading = 'reading';
    case completed = 'completed';
    case on_hold = 'on_hold';
    case dropped = 'dropped';
    case plan_to_read = 'plan_to_read';
}
