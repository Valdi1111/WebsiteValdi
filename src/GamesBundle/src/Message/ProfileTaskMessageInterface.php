<?php

namespace App\GamesBundle\Message;

interface ProfileTaskMessageInterface extends TaskMessageInterface
{
    public function getGameProfileId(): int;
}
