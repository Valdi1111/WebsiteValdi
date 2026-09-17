<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\ShopStatusCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<ShopStatusCheckBatchMessage>
 */
#[AsMessageHandler]
class ShopStatusCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(ShopStatusCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new ShopStatusCheckProfileMessage($gameProfileId);
    }
}