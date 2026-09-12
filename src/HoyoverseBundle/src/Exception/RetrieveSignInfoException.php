<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveSignInfoException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}