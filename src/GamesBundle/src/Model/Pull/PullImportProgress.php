<?php

namespace App\GamesBundle\Model\Pull;

final readonly class PullImportProgress
{
    /**
     * @param list<string>                             $warnings
     * @param int|null                                 $updated  Existing records rewritten (multi-file imports), null when not tracked
     * @param array<string, array<string, mixed>>|null $banners  Per-banner progress, keyed like the operation "banners" (null = unchanged)
     */
    public function __construct(
        public string $phase,
        public int $total,
        public int $processed,
        public int $imported,
        public int $skipped,
        public array $warnings = [],
        public ?int $updated = null,
        public ?array $banners = null,
    ) {
    }
}
