<?php

namespace App\Tests\GamesBundle\Chat\Profiles\Fixtures;

use App\Tests\GamesBundle\Status\Fixtures\FakeEndgamesProfile;

/**
 * Fake profile with the fields of OverviewSettings (read by the property accessor) and its notification platforms.
 */
class OverviewProfile extends FakeEndgamesProfile
{
    public bool $checkIn = false;
    public bool $codeRedeem = false;
    public bool $staminaCheck = false;
    public bool $currencyCheck = false;
    public ?int $currencyThreshold = -1;
    public bool $dailiesCheck = false;
    public bool $weekliesCheck = true;
    public bool $endgamesCheck = false;
    public bool $syncPulls = false;

    /** @var list<string> */
    private array $notificationPlatforms = [];

    public function getNotificationPlatforms(): array
    {
        return $this->notificationPlatforms;
    }

    /**
     * @param list<string> $platforms
     */
    public function setNotificationPlatforms(array $platforms): static
    {
        $this->notificationPlatforms = $platforms;

        return $this;
    }
}
