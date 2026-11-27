<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Repository\UserRepository;

/**
 * The site user behind a chat account: the one who linked it on the profile page (Discord, Telegram), or the user id
 * itself for chat:run.
 *
 * A disabled user is resolved like any other: the callers refuse it (ChatUser::$user->isDisabled()) with its own
 * message, instead of telling it to link an account it already linked.
 */
class ChatUserResolver
{
    public const string DISABLED = 'Your account has been disabled.';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function resolve(ChatPlatform $platform, string $platformUserId): ?ChatUser
    {
        if ($platformUserId === '') {
            return null;
        }

        $user = match ($platform) {
            ChatPlatform::Discord => $this->userRepository->findOneByDiscordUserId($platformUserId),
            // The Telegram user id is also the id of the private chat with the bot
            ChatPlatform::Telegram => $this->userRepository->findOneByTelegramChatId($platformUserId),
            ChatPlatform::Cli => ctype_digit($platformUserId) ? $this->userRepository->find((int) $platformUserId) : null,
        };

        return $user instanceof User ? new ChatUser($user, $platform, $platformUserId) : null;
    }

    /**
     * The user of a queued request again, in the worker (an entity does not travel in the queue): null when it was
     * deleted or unlinked from that chat account since.
     */
    public function reload(int $userId, ChatPlatform $platform, string $platformUserId): ?ChatUser
    {
        $user = $this->userRepository->find($userId);
        if (!$user instanceof User || !self::isLinkedTo($user, $platform, $platformUserId)) {
            return null;
        }

        return new ChatUser($user, $platform, $platformUserId);
    }

    private static function isLinkedTo(User $user, ChatPlatform $platform, string $platformUserId): bool
    {
        return match ($platform) {
            ChatPlatform::Discord => $user->getDiscordUserId() === $platformUserId,
            ChatPlatform::Telegram => $user->getTelegramChatId() === $platformUserId,
            ChatPlatform::Cli => (string) $user->getId() === $platformUserId,
        };
    }
}
