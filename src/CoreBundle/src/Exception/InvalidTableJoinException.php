<?php

namespace App\CoreBundle\Exception;

class InvalidTableJoinException extends \RuntimeException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}
