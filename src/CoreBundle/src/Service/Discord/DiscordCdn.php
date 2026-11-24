<?php

namespace App\CoreBundle\Service\Discord;

/**
 * Image URLs of the Discord CDN (https://discord.com/developers/docs/reference#image-formatting).
 */
final class DiscordCdn
{
    public const string BASE_URL = 'https://cdn.discordapp.com';

    /**
     * Avatar of a user: the uploaded one from its hash, else the default one of the new username system.
     * A hash goes stale when the user changes avatar on Discord, until the account is linked again: the frontend
     * needs a fallback for an image that does not load.
     *
     * @param string      $userId     snowflake
     * @param string|null $avatarHash null for the default avatar
     * @param int         $size       power of two between 16 and 4096; ignored by the default avatar
     */
    public static function avatarUrl(string $userId, ?string $avatarHash, int $size = 128): string
    {
        if ($avatarHash === null) {
            // (user_id >> 22) % 6 for the users on the new username system; the legacy ones used discriminator % 5
            return sprintf('%s/embed/avatars/%d.png', self::BASE_URL, ((int) $userId >> 22) % 6);
        }

        // Static WebP also for the animated ones ("a_" hash): no animation without "animated=true"
        return sprintf('%s/avatars/%s/%s.webp?size=%d', self::BASE_URL, $userId, $avatarHash, $size);
    }
}
