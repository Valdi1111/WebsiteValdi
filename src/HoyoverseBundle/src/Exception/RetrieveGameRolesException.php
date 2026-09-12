<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveGameRolesException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}