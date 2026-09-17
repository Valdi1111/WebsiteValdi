<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\RealmCurrencyCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<RealmCurrencyCheckBatchMessage>
 */
#[AsMessageHandler]
class RealmCurrencyCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(RealmCurrencyCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new RealmCurrencyCheckProfileMessage($gameProfileId);
    }
}