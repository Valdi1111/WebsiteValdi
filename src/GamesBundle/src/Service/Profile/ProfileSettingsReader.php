<?php

namespace App\GamesBundle\Service\Profile;

use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\ProfileSettingValue;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Settings of a game profile with their values, from the definitions of its provider (getSettings()): only those the
 * game supports. Sections read them to tell what the user turned on (auto check-in, endgame targets...); /profiles
 * lists them.
 */
class ProfileSettingsReader
{
    public function __construct(
        private readonly GameProfileRegistry $registry,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @return array<string, ProfileSettingValue> by setting key, in the order of the definitions
     */
    public function read(GameProfileContext $context): array
    {
        $provider = $this->registry->getProviders()[$context->ref->provider] ?? null;
        if ($provider === null) {
            return [];
        }

        $values = [];
        foreach ($provider->getSettings() as $setting) {
            if (!is_a($context->gameService, $setting->capability)) {
                continue;
            }

            $threshold = $setting->thresholdField !== null ? $this->propertyAccessor->getValue($context->profile, $setting->thresholdField) : null;
            $extra = [];
            foreach ($setting->extraFields as $field) {
                $extra[$field] = $this->propertyAccessor->getValue($context->profile, $field);
            }

            $values[$setting->key] = new ProfileSettingValue(
                setting: $setting,
                enabled: (bool) $this->propertyAccessor->getValue($context->profile, $setting->field),
                threshold: is_numeric($threshold) ? (int) $threshold : null,
                extra: $extra,
            );
        }

        return $values;
    }
}
