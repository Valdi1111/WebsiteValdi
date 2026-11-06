<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\SkportCheckInBatchMessage;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<SkportCheckInBatchMessage>
 */
#[AsMessageHandler]
class SkportCheckInBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(SkportCheckInBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        yield new SkportCheckInProfileMessage($gameProfileId);
    }
}
