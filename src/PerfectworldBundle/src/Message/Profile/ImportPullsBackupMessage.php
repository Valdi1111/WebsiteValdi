<?php

namespace App\PerfectworldBundle\Message\Profile;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Imports one or more uploaded export files into a game profile (files are deleted afterwards).
 */
#[AsMessage('perfectworld_pulls')]
class ImportPullsBackupMessage
{
    /**
     * @param list<string> $filePaths
     */
    public function __construct(
        private readonly string $operationId,
        private readonly int $gameProfileId,
        private readonly string $importerKey,
        private readonly array $filePaths,
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

    /**
     * @return list<string>
     */
    public function getFilePaths(): array
    {
        return $this->filePaths;
    }
}
