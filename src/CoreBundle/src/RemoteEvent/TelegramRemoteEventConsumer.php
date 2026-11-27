<?php

namespace App\CoreBundle\RemoteEvent;

use App\CoreBundle\Service\Chat\Telegram\TelegramUpdateHandler;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * The updates posted to /webhook/telegram (TelegramRequestParser), in the core_chat worker: the name is the type of
 * the webhook route (framework.webhook.routing.telegram).
 */
#[AsRemoteEventConsumer(self::TYPE)]
final class TelegramRemoteEventConsumer implements ConsumerInterface
{
    /** The webhook type: /webhook/telegram */
    public const string TYPE = 'telegram';

    public function __construct(
        private readonly TelegramUpdateHandler $handler,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        $this->handler->handle($event->getPayload());
    }
}
