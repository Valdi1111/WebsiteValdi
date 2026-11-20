<?php

namespace App\GamesBundle\Model\Pull;

final readonly class PullImportSummary
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $importerKey,
        public string $importerLabel,
        public int $total,
        public int $imported,
        public int $skipped,
        public array $warnings = [],
        public bool $dryRun = false,
    ) {
    }
}
