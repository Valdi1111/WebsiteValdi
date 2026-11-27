<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Model\Chat\ChatReplyTarget;

/**
 * A sender of one platform that records the answers, or throws $exception.
 */
class RecordingChatReplySender implements ChatReplySenderInterface
{
    /** @var list<array{ChatReplyTarget, ChatReply, ChatReplyMode}> */
    public array $sent = [];
    public ?\Throwable $exception = null;

    public function __construct(
        private readonly ChatPlatform $platform = ChatPlatform::Discord,
    ) {
    }

    public function supports(ChatPlatform $platform): bool
    {
        return $platform === $this->platform;
    }

    public function send(ChatReplyTarget $target, ChatReply $reply, ChatReplyMode $mode): void
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
        $this->sent[] = [$target, $reply, $mode];
    }
}
