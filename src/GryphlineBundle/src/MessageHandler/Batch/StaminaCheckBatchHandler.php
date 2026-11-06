<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
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

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        yield new StaminaCheckProfileMessage($gameProfileId);
    }
}
