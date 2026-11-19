<?php

namespace App\HoyoverseBundle\Model\Pull;

final readonly class PullBackupValidationResult
{
    public function __construct(
        public bool $valid,
        public int $estimatedCount = 0,
        public ?string $error = null,
    ) {
    }

    public static function ok(int $estimatedCount): self
    {
        return new self(true, $estimatedCount, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, 0, $error);
    }
}
