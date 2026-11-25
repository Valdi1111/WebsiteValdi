<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

/**
 * Fake profile with stored endgame settings, as HoyoverseGameProfile::getEndgamesSettings() keeps them.
 */
class FakeEndgamesProfile extends FakeGameProfile
{
    /** @var array<string, array<string, string>>|null */
    private ?array $endgamesSettings = null;

    /**
     * @return array<string, array<string, string>>|null
     */
    public function getEndgamesSettings(): ?array
    {
        return $this->endgamesSettings;
    }

    /**
     * @param array<string, array<string, string>>|null $settings
     */
    public function setEndgamesSettings(?array $settings): static
    {
        $this->endgamesSettings = $settings;

        return $this;
    }
}
