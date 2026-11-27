<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use App\CoreBundle\Attribute\AsChatAction;
use App\CoreBundle\Model\Chat\ChatActionHandlerInterface;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatUser;

/**
 * A button handler that refuses with $refusal, and answers with $reply or throws $exception.
 */
#[AsChatAction('refresh')]
class RefreshChatAction implements ChatActionHandlerInterface
{
    public ?string $refusal = null;
    public ?ChatReply $reply = null;
    public ?\Throwable $exception = null;

    /** @var list<ChatActionRef> */
    public array $handled = [];

    public function authorize(ChatUser $user, ChatActionRef $ref): ?string
    {
        return $this->refusal;
    }

    public function handle(ChatUser $user, ChatActionRef $ref, string $locale): ChatReply
    {
        $this->handled[] = $ref;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->reply ?? ChatReply::text('refreshed ' . implode(',', $ref->args));
    }
}
