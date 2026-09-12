<?php

namespace App\HoyoverseBundle\Exception;

class NoAwardsDataException extends HoyolabException
{

    public function __construct()
    {
        parent::__construct("No awards data available");
    }

}