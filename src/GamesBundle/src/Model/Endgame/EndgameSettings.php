<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * Endgames Reminder settings of a profile, stored as JSON: {"<mode key>": {"<variant key>": "<value>"}}, the value
 * being an EndgameReminder or, on a variant with levels, one of its level keys, e.g.
 * {"deadly_assault": {"reminder": "full_clear", "hard_reminder": "off"}, "imaginarium_theater": {"reminder": "visionary"}}.
 * Only the modes and variants declared by the game are kept; what is missing or invalid takes the variant's default.
 * On a variant with levels, "full_clear" stands for the top level and is stored as its key.
 */
final class EndgameSettings
{
    /**
     * @param list<EndgameDefinition> $definitions
     * @param mixed                   $settings    stored or submitted value, any shape
     * @return array<string, array<string, EndgameTarget>> mode key => variant key => target
     */
    public static function resolve(array $definitions, mixed $settings): array
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            $mode = is_array($settings) && is_array($settings[$definition->key] ?? null) ? $settings[$definition->key] : [];
            foreach ($definition->variants as $variant) {
                $resolved[$definition->key][$variant->key] = $variant->findTarget($mode[$variant->key] ?? null)
                    ?? $variant->getDefaultTarget();
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
            static fn (array $variants) => array_map(static fn (EndgameTarget $target) => $target->getValue(), $variants),
            self::resolve($definitions, $settings)
        );
    }
}
