<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\DailiesReminderBatchMessage;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<DailiesReminderBatchMessage>
 */
#[AsMessageHandler]
class DailiesReminderBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(DailiesReminderBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new DailiesReminderProfileMessage($gameProfileId);
    }
}