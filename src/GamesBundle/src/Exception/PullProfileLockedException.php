<?php

namespace App\GamesBundle\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * Another pull operation (sync, import, manual edit) holds the profile lock. Expected contention,
 * mapped to 409 on API routes, so it is not logged as a critical error.
 */
#[WithLogLevel(LogLevel::NOTICE)]
class PullProfileLockedException extends \RuntimeException
{
}
