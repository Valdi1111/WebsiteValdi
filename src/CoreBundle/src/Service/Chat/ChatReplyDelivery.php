<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sends the answers of the core_chat worker through the sender of their platform (ChatReplySenderInterface).
 */
class ChatReplyDelivery
{
    /** A Discord interaction token lasts 15 minutes: one of margin for the run and the call */
    public const int MAX_AGE_SECONDS = 14 * 60;

    /**
     * @param iterable<ChatReplySenderInterface> $senders
     */
    public function __construct(
        #[AutowireIterator(ChatReplySenderInterface::TAG)]
        private readonly iterable $senders,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the request is too old to be answered: the worker then skips it instead of running it for nothing.
     */
    public function isExpired(ChatReplyTarget $target): bool
    {
        return $this->clock->now()->getTimestamp() - $target->receivedAt->getTimestamp() > self::MAX_AGE_SECONDS;
    }

    /**
     * Never throws: the transport has no retry, and the platform would refuse a late answer anyway. Failures are logged
     * with the platform and the HTTP status only (the target holds the interaction token).
     *
     * @return bool whether the platform accepted the answer
     */
    public function send(ChatReplyTarget $target, ChatReply $reply, ChatReplyMode $mode): bool
    {
        $context = ['platform' => $target->platform->value, 'mode' => $mode->value];
        $sender = null;
        foreach ($this->senders as $candidate) {
            if ($candidate->supports($target->platform)) {
                $sender = $candidate;
                break;
            }
        }
        if ($sender === null) {
            $this->logger->error('No chat reply sender for {platform}', $context);

            return false;
        }

        try {
            $sender->send($target, $reply, $mode);

            return true;
        } catch (ChatReplyNotSentException $e) {
            $this->logger->error('Chat reply not sent: {error} ({status})', $context + ['error' => $e::class, 'status' => $e->statusCode]);
        } catch (\Throwable $e) {
            $this->logger->error('Chat reply not sent: {error}', $context + ['error' => $e::class, 'code' => $e->getCode()]);
        }

        return false;
    }
}
