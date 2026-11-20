<?php

namespace App\GryphlineBundle\Message\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('gryphline_pulls')]
class ImportPullsBackupMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly string $operationId,
        private readonly int $gameProfileId,
        private readonly string $importerKey,
        private readonly string $filePath,
        private readonly bool $deleteFileAfter = true,
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

    public function getImporterKey(): string
    {
        return $this->importerKey;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function shouldDeleteFileAfter(): bool
    {
        return $this->deleteFileAfter;
    }

    public function getLockAttempt(): int
    {
        return $this->lockAttempt;
    }
}
