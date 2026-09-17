<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\HoyolabCheckInBatchMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<HoyolabCheckInBatchMessage>
 */
#[AsMessageHandler]
class HoyolabCheckInBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(HoyolabCheckInBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new HoyolabCheckInProfileMessage($gameProfileId);
    }
}