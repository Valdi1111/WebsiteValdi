<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\MimoCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\MimoCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<MimoCheckBatchMessage>
 */
#[AsMessageHandler]
class MimoCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(MimoCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new MimoCheckProfileMessage($gameProfileId);
    }
}