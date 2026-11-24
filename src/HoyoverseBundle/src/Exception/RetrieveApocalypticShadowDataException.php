<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveApocalypticShadowDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
