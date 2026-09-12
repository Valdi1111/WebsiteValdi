<?php

namespace App\HoyoverseBundle\Message;

interface GameProfileTaskMessageInterface extends TaskMessageInterface
{
    public function getGameProfileId(): int;
}