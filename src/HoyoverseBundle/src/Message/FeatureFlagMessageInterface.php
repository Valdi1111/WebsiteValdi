<?php

namespace App\HoyoverseBundle\Message;

interface FeatureFlagMessageInterface
{
    /**
     * Returns the boolean property name on HoyoverseGameProfile to filter against.
     * e.g. "dailiesCheck", "staminaCheck", "mimoCheck", etc.
     */
    public function getFeatureFlagField(): string;
}