<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveNotesDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}