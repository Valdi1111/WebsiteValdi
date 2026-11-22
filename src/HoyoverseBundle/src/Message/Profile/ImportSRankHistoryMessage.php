<?php

namespace App\HoyoverseBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Imports the official S-rank history of a profile (SRankHistoryImportService) for a tracked operation.
 */
#[AsMessage('hoyoverse_pulls')]
class ImportSRankHistoryMessage
{
    public function __construct(
        private readonly string $operationId,
        private readonly int $gameProfileId,
        private readonly int $lockAttempt = 0,
    ) {
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }

    public function getLockAttempt(): int
    {
        return $this->lockAttempt;
    }
}
