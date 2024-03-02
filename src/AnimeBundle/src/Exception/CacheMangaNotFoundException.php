<?php

namespace App\AnimeBundle\Exception;

class CacheMangaNotFoundException extends \RuntimeException
{

    public function __construct(int $id)
    {
        parent::__construct("Manga not found in MyAnimeList cache. Manga id = " . $id);
    }

}