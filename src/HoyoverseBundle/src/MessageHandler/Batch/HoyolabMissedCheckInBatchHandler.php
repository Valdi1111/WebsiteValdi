<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\HoyolabMissedCheckInBatchMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabMissedCheckInProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<HoyolabMissedCheckInBatchMessage>
 */
#[AsMessageHandler]
class HoyolabMissedCheckInBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(HoyolabMissedCheckInBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new HoyolabMissedCheckInProfileMessage($gameProfileId);
    }
}