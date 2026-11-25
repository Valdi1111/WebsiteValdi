<?php

namespace App\GamesBundle\Model\Profile;

/**
 * One setting of a game profile: a feature the user turns on or off, available when the game service implements the
 * capability. The single definition of the settings of a provider (its <Provider>ProfileSettings class): the profile
 * normalizer derives from it which fields a game exposes and accepts, and the status layer reads the values with it.
 *
 * The key is the same across providers for the same feature (check_in is hoyolabCheckIn on HoYoverse and
 * skportCheckIn on Gryphline); fields are the property names of the profile entity.
 */
final readonly class ProfileSetting
{
    /**
     * @param class-string $capability  interface the game service must implement
     * @param list<string> $extraFields other fields of the same feature (endgamesSettings, sub-options...)
     */
    public function __construct(
        public string $key,
        public string $label,
        public ProfileSettingKind $kind,
        public string $capability,
        public string $field,
        public ?string $thresholdField = null,
        public array $extraFields = [],
    ) {
    }

    /**
     * @return list<string> every profile field of the setting: the flag, then the threshold and the extra fields
     */
    public function getFields(): array
    {
        return [$this->field, ...($this->thresholdField !== null ? [$this->thresholdField] : []), ...$this->extraFields];
    }

    /**
     * Fields of the settings by capability, in the order of the definitions: the map the profile normalizers use to
     * expose and accept only the fields of the capabilities of a game.
     *
     * @param list<self> $settings
     * @return array<class-string, list<string>>
     */
    public static function fieldsByCapability(array $settings): array
    {
        $fields = [];
        foreach ($settings as $setting) {
            $fields[$setting->capability] = [...$fields[$setting->capability] ?? [], ...$setting->getFields()];
        }

        return $fields;
    }
}
