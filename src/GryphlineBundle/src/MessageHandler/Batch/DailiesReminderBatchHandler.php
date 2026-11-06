<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
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

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        yield new DailiesReminderProfileMessage($gameProfileId);
    }
}
