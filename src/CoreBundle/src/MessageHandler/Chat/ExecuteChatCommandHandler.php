<?php

namespace App\CoreBundle\MessageHandler\Chat;

use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Service\Chat\ChatExecutor;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a queued chat command and answers it in place of the deferred answer, also with the error when it failed: the
 * user would otherwise wait on "thinking..." until the token expires. Never throws (no retry on core_chat).
 */
#[AsMessageHandler]
class ExecuteChatCommandHandler
{
    public function __construct(
        private readonly ChatExecutor $executor,
        private readonly ChatReplyDelivery $delivery,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteChatCommandMessage $message): void
    {
        if ($this->delivery->isExpired($message->target)) {
            $this->logger->warning('Chat command /{command} skipped: received too long ago to be answered', ['command' => $message->command, 'platform' => $message->platform->value]);

            return;
        }

        $result = $this->executor->executeCommand($message);
        $this->delivery->send($message->target, $result->reply, ChatReplyMode::Replace);
    }
}
