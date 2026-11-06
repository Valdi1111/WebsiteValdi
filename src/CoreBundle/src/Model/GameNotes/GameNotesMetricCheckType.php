<?php

namespace App\CoreBundle\Model\GameNotes;

enum GameNotesMetricCheckType
{
    case CURRENT_EQUALS_MAX;
    case CURRENT_EQUALS_ZERO;
}
