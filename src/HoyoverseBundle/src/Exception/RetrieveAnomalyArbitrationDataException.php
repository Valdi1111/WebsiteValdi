<?php

namespace App\HoyoverseBundle\Exception;

class RetrieveAnomalyArbitrationDataException extends HoyolabException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
