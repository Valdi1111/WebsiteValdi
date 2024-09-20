<?php

namespace App\AnimeBundle\Exception;

class InvalidUrlException extends \RuntimeException
{

    public function __construct()
    {
        parent::__construct("Invalid url.");
    }

}