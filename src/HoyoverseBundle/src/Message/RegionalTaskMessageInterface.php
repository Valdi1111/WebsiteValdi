<?php

namespace App\HoyoverseBundle\Message;

interface RegionalTaskMessageInterface extends TaskMessageInterface
{
    public function getTimezone(): string;
}