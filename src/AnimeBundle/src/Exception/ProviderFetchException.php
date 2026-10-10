<?php

namespace App\AnimeBundle\Exception;

use RuntimeException;
use Throwable;

class ProviderFetchException extends RuntimeException
{
    public function __construct(string $provider, int $statusCode, string $url, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('[%s] Failed to fetch page from URL "%s" (HTTP status %d).', $provider, $url, $statusCode),
            $statusCode,
            $previous
        );
    }
}
