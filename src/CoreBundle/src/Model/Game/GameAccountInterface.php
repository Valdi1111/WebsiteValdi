<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Entity\User;

interface GameAccountInterface
{
    public function getId(): ?int;

    public function getUser(): ?User;
}
