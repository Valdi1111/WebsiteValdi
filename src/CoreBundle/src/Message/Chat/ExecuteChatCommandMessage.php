<?php

namespace App\CoreBundle\Message\Chat;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;

/**
 * A command accepted in the synchronous phase (ChatDispatcherInterface), run by a core_chat worker. The user is
 * reloaded by id in the worker: an entity does not travel in the queue.
 */
final readonly class ExecuteChatCommandMessage
{
    /**
     * @param array<string, string|int|bool> $arguments
     */
    public function __construct(
        public int $userId,
        public ChatPlatform $platform,
        public string $platformUserId,
        public string $command,
        public ?string $subcommand,
        public array $arguments,
        public string $locale,
        public ChatReplyTarget $target,
    ) {
    }
}
