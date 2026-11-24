<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\Discord\DiscordCdn;

/**
 * The Discord account of the logged-in user, for the "Connected accounts" card (GET /api/me/discord).
 *
 * Without the Discord id as a field, but the avatar URL contains it: it is the user's own account, shown to them.
 */
final readonly class DiscordAccountView
{
    /**
     * @param string|null $avatarUrl uploaded or default avatar; it may not load when the user changed it on Discord
     */
    public function __construct(
        public bool $linked,
        public ?string $username,
        public ?string $globalName,
        public ?string $avatarUrl,
    ) {
    }

    public static function fromUser(User $user): self
    {
        $id = $user->getDiscordUserId();
        if ($id === null) {
            return new self(false, null, null, null);
        }

        return new self(
            true,
            // Linked by hand before the profile page could do it: no username until linked again from there
            $user->getDiscordUsername(),
            $user->getDiscordGlobalName(),
            DiscordCdn::avatarUrl($id, $user->getDiscordAvatar()),
        );
    }
}
