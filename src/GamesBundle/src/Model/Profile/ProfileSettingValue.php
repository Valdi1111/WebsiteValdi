<?php

namespace App\GamesBundle\Model\Profile;

/**
 * A setting of one profile with its stored values.
 */
final readonly class ProfileSettingValue
{
    /**
     * @param array<string, mixed> $extra value of each extra field of the setting, by field name
     */
    public function __construct(
        public ProfileSetting $setting,
        public bool $enabled,
        public ?int $threshold = null,
        public array $extra = [],
    ) {
    }
}
