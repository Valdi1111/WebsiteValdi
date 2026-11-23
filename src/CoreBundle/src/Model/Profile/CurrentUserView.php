<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\User;

/**
 * The logged-in user as the frontend sees it (GET /api/me).
 *
 * An explicit view instead of the entity: serializing User would also expose the password hash
 * and the Discord/Telegram ids.
 */
final readonly class CurrentUserView
{
    /**
     * @param list<string> $roles     assigned and inherited roles (UserRoleResolver::getReachableRoleNames())
     * @param string|null  $avatarUrl resized profile image (UserAvatarManager::getUrl()), null for the default one
     */
    public function __construct(
        public int $id,
        public string $email,
        public array $roles,
        public ?string $avatarUrl,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public static function fromUser(User $user, array $roles, ?string $avatarUrl): self
    {
        return new self((int) $user->getId(), (string) $user->getEmail(), $roles, $avatarUrl);
    }
}
