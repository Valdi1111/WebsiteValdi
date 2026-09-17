<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<WeekliesReminderBatchMessage>
 */
#[AsMessageHandler]
class WeekliesReminderBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(WeekliesReminderBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new WeekliesReminderProfileMessage($gameProfileId);
    }
}