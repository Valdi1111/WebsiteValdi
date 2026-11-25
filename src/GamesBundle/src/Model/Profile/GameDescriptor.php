<?php

namespace App\GamesBundle\Model\Profile;

/**
 * A game as the cross-provider layer sees it, built by the provider from its game service.
 *
 * The slug is short and stable (gi, hsr, zzz, hi3, tot, ake, nte: the initials also used by the loggers) and is what a
 * user types to pick a game; the aliases are other accepted names. Slugs and aliases are unique across providers.
 */
final readonly class GameDescriptor
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public string $provider,
        public string $gameBiz,
        public string $slug,
        public string $name,
        public ?string $iconUrl = null,
        public array $aliases = [],
    ) {
    }

    /**
     * Whether $name is the slug or one of the aliases, ignoring case.
     */
    public function matches(string $name): bool
    {
        $name = strtolower(trim($name));

        return $name !== '' && ($name === $this->slug || in_array($name, $this->aliases, true));
    }

    /**
     * Slug derived from a game name, for a game service the provider has no slug for ("Genshin Impact" -> "genshin-impact").
     */
    public static function slugify(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }
}
