<?php

namespace App\AnimeBundle\Exception;

use RuntimeException;
use Throwable;

class ScrapeParsingException extends RuntimeException
{
    public function __construct(string $serviceName, string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('[%s] Parsing error: %s', $serviceName, $message),
            0,
            $previous
        );
    }
}
