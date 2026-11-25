<?php

namespace App\GamesBundle\Model\Profile;

/**
 * Identity of a game profile across providers: profile ids are only unique within a provider (one table each).
 *
 * The string form "<provider>:<id>" (e.g. "hoyoverse:42") is what chat commands carry in autocomplete values and
 * button data. It is never trusted: GameProfileRegistry::resolve() checks that the profile belongs to the user.
 */
final readonly class GameProfileRef implements \Stringable
{
    private const string PATTERN = '/^([a-z][a-z0-9_]*):([1-9][0-9]*)$/';

    public function __construct(
        public string $provider,
        public int $id,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $provider) || $id < 1) {
            throw new \InvalidArgumentException(sprintf('Invalid game profile reference "%s:%d".', $provider, $id));
        }
    }

    /**
     * Reference from its string form, or null when the string is not one.
     */
    public static function tryFromString(string $value): ?self
    {
        if (!preg_match(self::PATTERN, $value, $matches) || strlen($matches[2]) > 18) {
            return null;
        }

        return new self($matches[1], (int) $matches[2]);
    }

    public function equals(self $other): bool
    {
        return $this->provider === $other->provider && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->provider . ':' . $this->id;
    }
}
