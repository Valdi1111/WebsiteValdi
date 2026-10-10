<?php

namespace App\AnimeBundle\Exception;

use RuntimeException;
use Throwable;

class UnsupportedProviderException extends RuntimeException
{
    public function __construct(string $trackerName, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Unsupported or unconfigured anime provider "%s".', $trackerName), $code, $previous);
    }
}
