<?php

namespace App\CoreBundle\Model\Telegram;

use App\CoreBundle\Exception\TelegramOAuthException;

/**
 * The Telegram account of a user, from the claims of the id token of "Log In With Telegram" (OpenID Connect) with
 * the "profile" scope.
 */
final readonly class TelegramUser
{
    /**
     * Length of the name columns of User. Telegram allows 32 characters for a username and 64 each for the first and
     * the last name, which the "name" claim joins: a longer name is cut rather than refused by the database.
     */
    public const int MAX_NAME_LENGTH = 64;

    /**
     * Length of the picture column of User. Telegram gives "https://t.me/i/userpic/320/<43 characters>.jpg": a URL
     * that does not fit is dropped, since a cut one would not load.
     */
    public const int MAX_PICTURE_LENGTH = 255;

    /**
     * @param string      $id       Telegram user id, also the id of the private chat with the bot (digits)
     * @param string|null $username public username without the @, null when the user has none
     * @param string|null $name     display name
     * @param string|null $picture  profile picture URL, null when the user has none or does not show it
     */
    public function __construct(
        public string $id,
        public ?string $username,
        public ?string $name,
        public ?string $picture = null,
    ) {
    }

    /**
     * @param array<mixed> $claims claims of a validated id token
     *
     * @throws TelegramOAuthException when the "id" claim is missing or not a user id
     */
    public static function fromIdTokenClaims(array $claims): self
    {
        // "id" and not "sub": "sub" is another identifier, unknown to the Bot API. Telegram gives it as a string, the
        // documentation as a number
        $id = $claims['id'] ?? null;
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (!is_string($id) || !ctype_digit($id) || ltrim($id, '0') === '') {
            throw new TelegramOAuthException('The Telegram id token has no user id: is the "profile" scope requested?');
        }

        return new self(
            $id,
            self::optionalName($claims['preferred_username'] ?? null),
            self::optionalName($claims['name'] ?? null),
            self::optionalPicture($claims['picture'] ?? null),
        );
    }

    /**
     * An https URL only: it becomes the src of an image on the profile page. Telegram redirects it to its CDN.
     */
    private static function optionalPicture(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_PICTURE_LENGTH || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
    }

    private static function optionalName(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX_NAME_LENGTH);
    }
}
