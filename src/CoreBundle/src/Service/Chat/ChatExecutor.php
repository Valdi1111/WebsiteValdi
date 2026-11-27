<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatExecutionResult;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;
use Psr\Log\LoggerInterface;

/**
 * Runs an accepted command or button press: in the core_chat worker (the message handlers) and in chat:run.
 *
 * Always returns an answer to send, since a deferred answer must always be closed: a failure (exception, empty answer,
 * user deleted, unlinked or disabled since the request) becomes a generic error, flagged as failed. Exceptions are logged
 * by class and code only: their messages can hold tokens or account data.
 */
class ChatExecutor
{
    public const string NOT_LINKED_ANYMORE = 'Your account is no longer linked to the site.';

    public function __construct(
        private readonly ChatUserResolver $userResolver,
        private readonly ChatCommandRegistry $commands,
        private readonly ChatActionRegistry $actions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executeCommand(ExecuteChatCommandMessage $message): ChatExecutionResult
    {
        return $this->run(
            $message->userId,
            $message->platform,
            $message->platformUserId,
            fn (ChatUser $user): ChatReply => $this->commands->get($message->command)->execute(new ChatRequest(
                $message->platform,
                $user,
                $message->command,
                $message->subcommand,
                $message->arguments,
                $message->locale,
            )),
            ['command' => $message->command, 'subcommand' => $message->subcommand],
        );
    }

    public function handleAction(HandleChatActionMessage $message): ChatExecutionResult
    {
        return $this->run(
            $message->userId,
            $message->platform,
            $message->platformUserId,
            fn (ChatUser $user): ChatReply => $this->actions->get($message->ref->handler)->handle($user, $message->ref, $message->locale),
            ['action' => $message->ref->handler],
        );
    }

    /**
     * @param \Closure(ChatUser): ChatReply $run
     * @param array<string, string|null>    $context names only for the logs, never the arguments
     */
    private function run(int $userId, ChatPlatform $platform, string $platformUserId, \Closure $run, array $context): ChatExecutionResult
    {
        $context['platform'] = $platform->value;
        try {
            $user = $this->userResolver->reload($userId, $platform, $platformUserId);
            if ($user === null) {
                return ChatExecutionResult::failed(ChatReply::error(self::NOT_LINKED_ANYMORE));
            }
            if ($user->user->isDisabled()) {
                return ChatExecutionResult::failed(ChatReply::error(ChatUserResolver::DISABLED));
            }

            $reply = $run($user);
        } catch (\Throwable $e) {
            $this->logger->error('Chat request failed: {error}', $context + ['error' => $e::class, 'code' => $e->getCode()]);

            return ChatExecutionResult::failed(ChatReply::error());
        }

        // The platforms reject an empty message, and the deferred answer would stay open
        if ($reply->isEmpty()) {
            $this->logger->error('Chat request answered with an empty reply', $context);

            return ChatExecutionResult::failed(ChatReply::error());
        }

        return ChatExecutionResult::succeeded($reply);
    }
}
