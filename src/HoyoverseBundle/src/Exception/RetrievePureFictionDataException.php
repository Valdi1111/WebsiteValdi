<?php

namespace App\HoyoverseBundle\Exception;

class RetrievePureFictionDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
