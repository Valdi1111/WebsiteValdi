<?php

namespace App\AnimeBundle\Exception;

class CacheRefreshException extends \RuntimeException
{

    public function __construct(string $type, \Exception $e)
    {
        parent::__construct("Couldn't refresh $type cache", 0, $e);
    }

}