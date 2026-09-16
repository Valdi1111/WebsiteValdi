<?php

namespace App\HoyoverseBundle\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

class GameRecord
{
    #[SerializedName('game_id')]
    private int $gameId;

    #[SerializedName('game_role_id')]
    private string $gameUid;

    #[SerializedName('nickname')]
    private string $nickname;

    #[SerializedName('region')]
    private string $region;

    #[SerializedName('level')]
    private int $level;

    #[SerializedName('data')]
    private array $data;

    #[SerializedName('region_name')]
    private string $regionName;

    #[SerializedName('url')]
    private string $hoyolabUrl;

    #[SerializedName('logo')]
    private string $iconUrl;

    #[SerializedName('game_name')]
    private string $gameName;

    public function getGameId(): int
    {
        return $this->gameId;
    }

    public function setGameId(int $gameId): GameRecord
    {
        $this->gameId = $gameId;
        return $this;
    }

    public function getGameUid(): string
    {
        return $this->gameUid;
    }

    public function setGameUid(string $gameUid): GameRecord
    {
        $this->gameUid = $gameUid;
        return $this;
    }

    public function getNickname(): string
    {
        return $this->nickname;
    }

    public function setNickname(string $nickname): GameRecord
    {
        $this->nickname = $nickname;
        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): GameRecord
    {
        $this->region = $region;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): GameRecord
    {
        $this->level = $level;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): GameRecord
    {
        $this->data = $data;
        return $this;
    }

    public function getRegionName(): string
    {
        return $this->regionName;
    }

    public function setRegionName(string $regionName): GameRecord
    {
        $this->regionName = $regionName;
        return $this;
    }

    public function getHoyolabUrl(): string
    {
        return $this->hoyolabUrl;
    }

    public function setHoyolabUrl(string $hoyolabUrl): GameRecord
    {
        $this->hoyolabUrl = $hoyolabUrl;
        return $this;
    }

    public function getIconUrl(): string
    {
        return $this->iconUrl;
    }

    public function setIconUrl(string $iconUrl): GameRecord
    {
        $this->iconUrl = $iconUrl;
        return $this;
    }

    public function getGameName(): string
    {
        return $this->gameName;
    }

    public function setGameName(string $gameName): GameRecord
    {
        $this->gameName = $gameName;
        return $this;
    }

}