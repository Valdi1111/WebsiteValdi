<?php

namespace App\AnimeBundle\Exception;

class UnhandledWebsiteException extends \RuntimeException
{

    public function __construct()
    {
        parent::__construct("This service cannot handle the given url.");
    }

}