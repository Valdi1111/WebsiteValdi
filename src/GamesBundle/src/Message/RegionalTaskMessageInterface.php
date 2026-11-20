<?php

namespace App\GamesBundle\Message;

interface RegionalTaskMessageInterface extends TaskMessageInterface
{
    public function getTimezone(): string;
}
