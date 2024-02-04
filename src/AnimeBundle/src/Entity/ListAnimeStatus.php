<?php

namespace App\AnimeBundle\Entity;

enum ListAnimeStatus: string
{
    case watching = 'watching';
    case completed = 'completed';
    case on_hold = 'on_hold';
    case dropped = 'dropped';
    case plan_to_watch = 'plan_to_watch';
}
