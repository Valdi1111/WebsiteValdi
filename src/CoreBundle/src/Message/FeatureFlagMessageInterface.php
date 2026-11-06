<?php

namespace App\CoreBundle\Message;

interface FeatureFlagMessageInterface
{
    /**
     * Returns the boolean property name on GameProfile to filter against.
     * e.g. "dailiesCheck", "staminaCheck", "weekliesCheck", etc.
     */
    public function getFeatureFlagField(): string;
}
