<?php

namespace App\HoyoverseBundle\Model;

use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;

class RuntimeAccountData
{
    private ParsedCookie $parsedCookie;

    private HoyoverseGameProfile $gameProfile;

    public function getParsedCookie(): ParsedCookie
    {
        return $this->parsedCookie;
    }

    public function setParsedCookie(ParsedCookie $parsedCookie): RuntimeAccountData
    {
        $this->parsedCookie = $parsedCookie;
        return $this;
    }

    public function getGameProfile(): HoyoverseGameProfile
    {
        return $this->gameProfile;
    }

    public function setGameProfile(HoyoverseGameProfile $gameProfile): RuntimeAccountData
    {
        $this->gameProfile = $gameProfile;
        return $this;
    }

    public function getAccount(): HoyoverseAccount
    {
        return $this->getGameProfile()->getAccount();
    }

    public function getUser(): User
    {
        return $this->getAccount()->getUser();
    }

}