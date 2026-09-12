<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveGameRecordsException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}