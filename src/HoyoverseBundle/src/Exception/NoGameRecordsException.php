<?php

namespace App\HoyoverseBundle\Exception;

class NoGameRecordsException extends HoyolabException
{

    public function __construct()
    {
        parent::__construct("No game data available");
    }

}