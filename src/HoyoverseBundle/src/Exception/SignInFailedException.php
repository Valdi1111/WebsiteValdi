<?php

namespace App\HoyoverseBundle\Exception;

class SignInFailedException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}