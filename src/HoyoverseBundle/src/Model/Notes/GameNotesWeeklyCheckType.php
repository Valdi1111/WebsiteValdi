<?php

namespace App\HoyoverseBundle\Model\Notes;

enum GameNotesWeeklyCheckType
{
    case CURRENT_EQUALS_MAX;
    case CURRENT_EQUALS_ZERO;
}