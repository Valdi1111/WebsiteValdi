<?php

namespace App\HoyoverseBundle\Exception;

class CookieUpdateFailedException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}