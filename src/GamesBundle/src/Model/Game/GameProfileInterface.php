<?php

namespace App\GamesBundle\Model\Game;

interface GameProfileInterface
{
    public function getId(): ?int;

    public function isActive(): ?bool;

    public function getGameUid(): ?string;

    public function getNickname(): ?string;

    public function getLevel(): ?int;

    /**
     * Game of the profile within its provider (hk4e_global, endfield...): with the provider, it finds the game service.
     */
    public function getGameBiz(): ?string;

    public function getGameName(): ?string;

    public function getIconUrl(): ?string;

    /**
     * Icon of the game provider (HoYoLAB, SKPort...), shown in place of the game icon when the profile has none.
     */
    public function getProviderIconUrl(): ?string;

    public function getParsedRegion(): ?string;

    public function getParsedTimezone(): ?string;

    public function getStaminaThreshold(): ?int;

    public function getNotificationPlatforms(): array;

    public function getAccount(): ?GameAccountInterface;
}
