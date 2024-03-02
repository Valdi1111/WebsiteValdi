<?php

namespace App\AnimeBundle\Exception;

class CacheAnimeNotFoundException extends \RuntimeException
{

    public function __construct(int $id)
    {
        parent::__construct("Anime not found in MyAnimeList cache. Anime id = " . $id);
    }

}