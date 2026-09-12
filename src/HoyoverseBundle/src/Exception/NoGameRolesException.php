<?php

namespace App\HoyoverseBundle\Exception;

class NoGameRolesException extends HoyolabException
{

    public function __construct()
    {
        parent::__construct("No game roles available");
    }

}