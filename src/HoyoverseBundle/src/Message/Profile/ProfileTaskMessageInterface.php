<?php

namespace App\HoyoverseBundle\Message\Profile;

use App\HoyoverseBundle\Message\TaskMessageInterface;

interface ProfileTaskMessageInterface extends TaskMessageInterface
{
    public function getGameProfileId(): int;
}