<?php

namespace App\AnimeBundle\Exception;

use RuntimeException;
use Throwable;

class ProviderFetchException extends RuntimeException
{
    public function __construct(string $serviceName, int $statusCode, string $url, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('[%s] Failed to fetch page from URL "%s" (HTTP status %d).', $serviceName, $url, $statusCode),
            $statusCode,
            $previous
        );
    }
}
