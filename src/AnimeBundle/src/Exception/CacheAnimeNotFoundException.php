<?php

namespace App\AnimeBundle\Exception;

use App\CoreBundle\Exception\EntityNotFoundException;

class CacheAnimeNotFoundException extends EntityNotFoundException
{

    public function __construct($id)
    {
        parent::__construct("Anime not found in MyAnimeList cache. (id = $id)");
    }

}