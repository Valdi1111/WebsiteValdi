<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * Endgames Reminder settings of a profile, stored as JSON: {"<mode key>": {"<variant key>": "<EndgameReminder>"}},
 * e.g. {"deadly_assault": {"reminder": "full_clear", "hard_reminder": "off"}}. Only the modes and variants declared
 * by the game are kept; what is missing or invalid takes the variant's default.
 */
final class EndgameSettings
{
    /**
     * @param list<EndgameDefinition> $definitions
     * @param mixed                   $settings    stored or submitted value, any shape
     * @return array<string, array<string, EndgameReminder>> mode key => variant key => reminder
     */
    public static function resolve(array $definitions, mixed $settings): array
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            $mode = is_array($settings) && is_array($settings[$definition->key] ?? null) ? $settings[$definition->key] : [];
            foreach ($definition->variants as $variant) {
                $value = $mode[$variant->key] ?? null;
                $resolved[$definition->key][$variant->key] = (is_string($value) ? EndgameReminder::tryFrom($value) : null)
                    ?? $variant->defaultReminder;
            }
        }

        return $resolved;
    }

    /**
     * Same as resolve(), with the values as stored and serialized.
     *
     * @param list<EndgameDefinition> $definitions
     * @return array<string, array<string, string>>
     */
    public static function normalize(array $definitions, mixed $settings): array
    {
        return array_map(
            static fn (array $variants) => array_map(static fn (EndgameReminder $reminder) => $reminder->value, $variants),
            self::resolve($definitions, $settings)
        );
    }
}
