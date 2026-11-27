<?php

namespace App\Tests\GamesBundle\Chat\Fixtures;

use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;

/**
 * Fake profile whose nickname and UID can be chosen (several profiles may share a nickname, as across games), and whose
 * game name follows its game biz ("full_game" → "Full Game").
 */
class ChatGameProfile extends FakeGameProfile
{
    private ?string $nickname = null;
    private ?string $uid = null;

    public function named(?string $nickname, ?string $uid = null): static
    {
        $this->nickname = $nickname;
        $this->uid = $uid;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname ?? parent::getNickname();
    }

    public function getGameUid(): ?string
    {
        return $this->uid ?? parent::getGameUid();
    }

    public function getGameName(): ?string
    {
        return ucwords(str_replace('_', ' ', (string) $this->getGameBiz()));
    }
}
