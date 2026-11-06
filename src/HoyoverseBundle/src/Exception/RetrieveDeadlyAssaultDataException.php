<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveDeadlyAssaultDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
