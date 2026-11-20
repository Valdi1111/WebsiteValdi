<?php

namespace App\GamesBundle\Model\Pull;

final readonly class PullImportProgress
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $phase,
        public int $total,
        public int $processed,
        public int $imported,
        public int $skipped,
        public array $warnings = [],
    ) {
    }
}
