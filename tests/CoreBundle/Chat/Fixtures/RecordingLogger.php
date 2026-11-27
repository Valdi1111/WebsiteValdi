<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use Psr\Log\AbstractLogger;

class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> level, message, context */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }

    /**
     * Everything logged, as one string: to check that a secret never reaches the logs.
     */
    public function dump(): string
    {
        return (string) json_encode($this->records);
    }
}
