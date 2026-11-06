<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveShiyuDefenseDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
