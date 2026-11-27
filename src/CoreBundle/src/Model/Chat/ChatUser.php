<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Entity\User;

/**
 * The site user behind a chat request, resolved from the account linked on the platform (ChatUserResolver).
 */
final readonly class ChatUser
{
    public function __construct(
        public User $user,
        public ChatPlatform $platform,
        public string $platformUserId,
    ) {
    }
}
