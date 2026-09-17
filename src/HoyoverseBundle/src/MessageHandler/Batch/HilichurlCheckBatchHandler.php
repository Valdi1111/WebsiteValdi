<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\HilichurlCheckBatchMessage;
use App\HoyoverseBundle\Message\Profile\HilichurlCheckProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<HilichurlCheckBatchMessage>
 */
#[AsMessageHandler]
class HilichurlCheckBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(HilichurlCheckBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        yield new HilichurlCheckProfileMessage($gameProfileId);
    }
}