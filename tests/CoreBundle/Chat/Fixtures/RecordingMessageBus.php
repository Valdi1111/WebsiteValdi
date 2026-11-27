<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A bus that keeps the dispatched messages, or throws $exception (a transport that cannot be reached).
 */
class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];
    public ?\Throwable $exception = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
