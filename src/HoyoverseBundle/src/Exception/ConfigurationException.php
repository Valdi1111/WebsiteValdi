<?php

namespace App\HoyoverseBundle\Exception;

class ConfigurationException extends \RuntimeException
{

    public function __construct(string $message)
    {
        parent::__construct($message);
    }

}