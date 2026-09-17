<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\StaminaCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<StaminaCheckBatchMessage>
 */
#[AsMessageHandler]
class StaminaCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(StaminaCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new StaminaCheckProfileMessage($gameProfileId);
    }
}