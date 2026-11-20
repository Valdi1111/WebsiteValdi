<?php

namespace App\GamesBundle\Model\Pull;

final readonly class PullBackupValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $error = null,
        public int $estimatedCount = 0,
    ) {
    }

    public static function ok(int $estimatedCount = 0): self
    {
        return new self(true, null, max(0, $estimatedCount));
    }

    public static function fail(string $error): self
    {
        return new self(false, $error, 0);
    }
}
