<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
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

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        yield new WeekliesReminderProfileMessage($gameProfileId);
    }
}
