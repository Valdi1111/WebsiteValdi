<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveStygianOnslaughtDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
