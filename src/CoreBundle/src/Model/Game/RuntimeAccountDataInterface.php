<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Entity\User;

interface RuntimeAccountDataInterface
{
    public function getGameProfile(): GameProfileInterface;

    public function getAccount(): GameAccountInterface;

    public function getUser(): ?User;
}
