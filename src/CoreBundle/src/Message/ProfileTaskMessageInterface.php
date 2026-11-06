<?php

namespace App\CoreBundle\Message;

interface ProfileTaskMessageInterface extends TaskMessageInterface
{
    public function getGameProfileId(): int;
}
