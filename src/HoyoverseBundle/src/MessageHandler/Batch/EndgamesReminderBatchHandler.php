<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\EndgamesReminderBatchMessage;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<EndgamesReminderBatchMessage>
 */
#[AsMessageHandler]
class EndgamesReminderBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(EndgamesReminderBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new EndgamesReminderProfileMessage($gameProfileId);
    }
}