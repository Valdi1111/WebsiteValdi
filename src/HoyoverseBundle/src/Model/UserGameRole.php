<?php

namespace App\HoyoverseBundle\Model;

class UserGameRole
{
    private ?string $gameBiz = null;

    private ?string $region = null;

    private ?string $regionName = null;

    private ?string $gameUid = null;

    private ?string $nickname = null;

    private ?int $level = null;

    public function getGameBiz(): ?string
    {
        return $this->gameBiz;
    }

    public function setGameBiz(?string $gameBiz): UserGameRole
    {
        $this->gameBiz = $gameBiz;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): UserGameRole
    {
        $this->region = $region;
        return $this;
    }

    public function getRegionName(): ?string
    {
        return $this->regionName;
    }

    public function setRegionName(?string $regionName): UserGameRole
    {
        $this->regionName = $regionName;
        return $this;
    }

    public function getGameUid(): ?string
    {
        return $this->gameUid;
    }

    public function setGameUid(?string $gameUid): UserGameRole
    {
        $this->gameUid = $gameUid;
        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): UserGameRole
    {
        $this->nickname = $nickname;
        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): UserGameRole
    {
        $this->level = $level;
        return $this;
    }

}