<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\ExpeditionsCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\ExpeditionsCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<ExpeditionsCheckBatchMessage>
 */
#[AsMessageHandler]
class ExpeditionsCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(ExpeditionsCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new ExpeditionsCheckProfileMessage($gameProfileId);
    }
}