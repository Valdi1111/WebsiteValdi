<?php

namespace App\PerfectworldBundle\Model\Pull;

/**
 * Header-level summary of an export file, used to validate an upload and pre-fill the progress tracker.
 */
final readonly class PullFileInspection
{
    /**
     * @param array<string, array{name: string, count: int}> $banners Banners contained in the file, by banner key
     */
    public function __construct(
        public ?string $gameUid,
        public string $exporterVersion,
        public array $banners,
    ) {
    }

    public function getTotalCount(): int
    {
        return array_sum(array_column($this->banners, 'count'));
    }
}
