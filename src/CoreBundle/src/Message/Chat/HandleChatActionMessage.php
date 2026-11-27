<?php

namespace App\CoreBundle\Message\Chat;

use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A button press authorized in the synchronous phase, run by a core_chat worker.
 */
#[AsMessage('core_chat')]
final readonly class HandleChatActionMessage
{
    public function __construct(
        public int $userId,
        public ChatPlatform $platform,
        public string $platformUserId,
        public ChatActionRef $ref,
        public string $locale,
        public ChatReplyTarget $target,
    ) {
    }
}
