<?php

namespace App\HoyoverseBundle\Exception;

/**
 * HoYoverse could not be reached or did not answer properly (no network, DNS, timeout, HTTP 5xx/429, unreadable body).
 *
 * It says nothing about the credentials: callers keep the account state and try again later instead of flagging it.
 * No previous exception is attached, so request details never end up in logs or notifications.
 */
class HoyolabUnavailableException extends HoyolabException
{
    public static function isUnavailableStatus(int $statusCode): bool
    {
        return $statusCode >= 500 || $statusCode === 429;
    }
}
