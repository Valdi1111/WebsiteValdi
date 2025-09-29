<?php

namespace App\CoreBundle\Exception;

class EntityNotFoundException extends \RuntimeException
{

    public function __construct($id)
    {
        parent::__construct("Entity not found. (id = $id)");
    }

}