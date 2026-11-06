<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveSpiralAbyssDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
