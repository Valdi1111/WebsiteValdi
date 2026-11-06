<?php

namespace App\CoreBundle\Message;

interface RegionalTaskMessageInterface extends TaskMessageInterface
{
    public function getTimezone(): string;
}
