<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\User;

/**
 * The Telegram account of the logged-in user, for the "Connected accounts" card (GET /api/me/telegram).
 *
 * Without the Telegram id: the names are enough to recognize the account.
 */
final readonly class TelegramAccountView
{
    /**
     * @param string|null $username without the @; null when the account has none, and for the ids set by hand
     * @param string|null $name     null for the ids set by hand, until linked again from the profile page
     */
    public function __construct(
        public bool $linked,
        public ?string $username,
        public ?string $name,
    ) {
    }

    public static function fromUser(User $user): self
    {
        if ($user->getTelegramChatId() === null) {
            return new self(false, null, null);
        }

        return new self(true, $user->getTelegramUsername(), $user->getTelegramName());
    }
}
