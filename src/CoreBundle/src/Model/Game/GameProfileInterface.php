<?php

namespace App\CoreBundle\Model\Game;

interface GameProfileInterface
{
    public function getId(): ?int;

    public function isActive(): ?bool;

    public function getGameUid(): ?string;

    public function getNickname(): ?string;

    public function getLevel(): ?int;

    public function getGameName(): ?string;

    public function getIconUrl(): ?string;

    public function getParsedRegion(): ?string;

    public function getParsedTimezone(): ?string;

    public function getStaminaThreshold(): ?int;

    public function getNotificationPlatforms(): array;

    public function getAccount(): ?GameAccountInterface;
}
