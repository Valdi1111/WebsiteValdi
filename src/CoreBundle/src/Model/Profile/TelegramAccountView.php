<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\User;

/**
 * The Telegram account of the logged-in user, for the "Connected accounts" card (GET /api/me/telegram).
 *
 * Without the Telegram id: the names and the picture are enough to recognize the account.
 */
final readonly class TelegramAccountView
{
    /**
     * @param string|null $username  without the @; null when the account has none, and for the ids set by hand
     * @param string|null $name      null for the ids set by hand, until linked again from the profile page
     * @param string|null $avatarUrl profile picture, if the user showed one when linked: Telegram redirects it to the
     *                               current one, and browsers keep that redirect for an hour
     */
    public function __construct(
        public bool $linked,
        public ?string $username,
        public ?string $name,
        public ?string $avatarUrl,
    ) {
    }

    public static function fromUser(User $user): self
    {
        if ($user->getTelegramChatId() === null) {
            return new self(false, null, null, null);
        }

        return new self(true, $user->getTelegramUsername(), $user->getTelegramName(), $user->getTelegramPicture());
    }
}
