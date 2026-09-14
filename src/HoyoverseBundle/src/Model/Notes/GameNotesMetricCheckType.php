<?php

namespace App\HoyoverseBundle\Model\Notes;

enum GameNotesMetricCheckType
{
    case CURRENT_EQUALS_MAX;
    case CURRENT_EQUALS_ZERO;
}