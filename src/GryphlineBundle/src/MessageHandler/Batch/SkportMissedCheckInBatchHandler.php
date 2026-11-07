<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\SkportMissedCheckInBatchMessage;
use App\GryphlineBundle\Message\Profile\SkportMissedCheckInProfileMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractBatchTaskMessageHandler<SkportMissedCheckInBatchMessage>
 */
#[AsMessageHandler]
class SkportMissedCheckInBatchHandler extends AbstractBatchTaskMessageHandler
{
    public function __invoke(SkportMissedCheckInBatchMessage $message): void
    {
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        yield new SkportMissedCheckInProfileMessage($gameProfileId);
    }
}
