<?php

namespace App\CoreBundle\MessageHandler\Chat;

use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Service\Chat\ChatExecutor;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a queued button press: its answer replaces the message of the button, while a failure is sent apart, only to
 * the user, so the message with its data and buttons stays. Never throws (no retry on core_chat).
 */
#[AsMessageHandler]
class HandleChatActionHandler
{
    public function __construct(
        private readonly ChatExecutor $executor,
        private readonly ChatReplyDelivery $delivery,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandleChatActionMessage $message): void
    {
        if ($this->delivery->isExpired($message->target)) {
            $this->logger->warning('Chat action {action} skipped: received too long ago to be answered', ['action' => $message->ref->handler, 'platform' => $message->platform->value]);

            return;
        }

        $result = $this->executor->handleAction($message);
        $this->delivery->send($message->target, $result->reply, $result->failed ? ChatReplyMode::FollowUp : ChatReplyMode::Replace);
    }
}
