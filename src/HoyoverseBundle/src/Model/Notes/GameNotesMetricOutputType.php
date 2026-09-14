<?php

namespace App\HoyoverseBundle\Model\Notes;

enum GameNotesMetricOutputType
{
    /**
     * es. "3/5"
     */
    case FRACTION;
    /**
     * es. "60%"
     */
    case PERCENTAGE;
    /**
     * es. "Completed" / "Incompleted"
     */
    case STATUS;
}
