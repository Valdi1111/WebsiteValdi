<?php

namespace App\AnimeBundle\Exception;

use App\CoreBundle\Exception\EntityNotFoundException;

class CacheMangaNotFoundException extends EntityNotFoundException
{

    public function __construct($id)
    {
        parent::__construct("Manga not found in MyAnimeList cache. (id = $id)");
    }

}