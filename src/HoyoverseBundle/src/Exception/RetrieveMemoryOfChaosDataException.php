<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveMemoryOfChaosDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
