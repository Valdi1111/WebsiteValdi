<?php

namespace App\GryphlineBundle\Model;

use App\CoreBundle\Entity\User;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;

class RuntimeAccountData implements \App\CoreBundle\Model\Game\RuntimeAccountDataInterface
{
    private GryphlineGameProfile $gameProfile;
    private ?SkportSessionCredentials $credentials = null;

    public function getGameProfile(): GryphlineGameProfile
    {
        return $this->gameProfile;
    }

    public function setGameProfile(GryphlineGameProfile $gameProfile): self
    {
        $this->gameProfile = $gameProfile;
        return $this;
    }

    public function getAccount(): GryphlineAccount
    {
        return $this->getGameProfile()->getAccount();
    }

    public function getUser(): User
    {
        return $this->getAccount()->getUser();
    }

    public function getCredentials(): ?SkportSessionCredentials
    {
        return $this->credentials;
    }

    public function setCredentials(?SkportSessionCredentials $credentials): self
    {
        $this->credentials = $credentials;
        return $this;
    }
}
