<?php

namespace App\CoreBundle\Model\Discord;

use App\CoreBundle\Exception\DiscordOAuthException;

/**
 * The Discord account of a user, from GET /users/@me with the "identify" scope.
 *
 * The discriminator is not kept: users on the new username system all have "0", and the few legacy ones are shown
 * by username only.
 */
final readonly class DiscordUser
{
    /**
     * Length of the name columns of User. Discord allows 32 characters, but may count them differently than MySQL
     * (e.g. emoji sequences in a display name), so a longer name is cut rather than refused by the database.
     */
    public const int MAX_NAME_LENGTH = 64;

    /**
     * @param string      $id         snowflake (unsigned 64-bit), kept as the string the API gives
     * @param string      $username   unique handle (e.g. "valdi")
     * @param string|null $globalName display name, null when the user has not set one
     * @param string|null $avatar     avatar hash, null for the default avatar (DiscordCdn::avatarUrl())
     */
    public function __construct(
        public string $id,
        public string $username,
        public ?string $globalName,
        public ?string $avatar,
    ) {
    }

    /**
     * @param array<mixed> $data the user object of the Discord API
     *
     * @throws DiscordOAuthException when the id or the username is missing
     */
    public static function fromApi(array $data): self
    {
        $id = $data['id'] ?? null;
        $username = $data['username'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || !is_string($username) || $username === '') {
            throw new DiscordOAuthException('Discord returned a user without id or username.');
        }

        $globalName = self::optionalString($data['global_name'] ?? null);

        return new self(
            $id,
            mb_substr($username, 0, self::MAX_NAME_LENGTH),
            $globalName === null ? null : mb_substr($globalName, 0, self::MAX_NAME_LENGTH),
            self::optionalString($data['avatar'] ?? null),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
