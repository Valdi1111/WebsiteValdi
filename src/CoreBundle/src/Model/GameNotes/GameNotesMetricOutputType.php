<?php

namespace App\CoreBundle\Model\GameNotes;

enum GameNotesMetricOutputType
{
    /**
     * e.g. "3/5"
     */
    case FRACTION;

    /**
     * e.g. "60%"
     */
    case PERCENTAGE;

    /**
     * e.g. "Completed" / "Incomplete"
     */
    case STATUS;
}
