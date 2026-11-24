<?php

namespace App\CoreBundle\Model\Admin;

use App\CoreBundle\Entity\User;

/**
 * A user as the users admin page sees it (GET /api/admin/users).
 *
 * An explicit view instead of the entity: serializing User would also expose the password hash. Discord and Telegram
 * give whether an account is linked and its username, not its id.
 */
final readonly class AdminUserView
{
    /**
     * @param list<string>            $roles            assigned role names, sorted: the inherited ones are not listed
     * @param string|null             $discordUsername  null when not linked, or linked by hand (no name until linked
     *                                                  again from the profile page)
     * @param string|null             $telegramUsername without the @; null when not linked, linked by hand, or the
     *                                                  Telegram account has none
     * @param \DateTimeImmutable|null $disabledAt       null while the account is enabled
     */
    public function __construct(
        public int $id,
        public string $email,
        public array $roles,
        public bool $discordLinked,
        public ?string $discordUsername,
        public bool $telegramLinked,
        public ?string $telegramUsername,
        public bool $enabled,
        public ?\DateTimeImmutable $disabledAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        $roles = array_values($user->getRoles());
        sort($roles);

        return new self(
            (int) $user->getId(),
            (string) $user->getEmail(),
            $roles,
            $user->getDiscordUserId() !== null,
            $user->getDiscordUsername(),
            $user->getTelegramChatId() !== null,
            $user->getTelegramUsername(),
            !$user->isDisabled(),
            $user->getDisabledAt(),
        );
    }
}
