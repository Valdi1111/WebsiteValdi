<?php

namespace App\CoreBundle\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request to /discord/interactions whose Ed25519 signature is missing or wrong: 401, as Discord requires. Logged as
 * info, not as the error of any other 4xx: Discord sends such requests on purpose, when the endpoint URL is saved and
 * now and then afterwards, to check that it is refused.
 */
#[WithLogLevel(LogLevel::INFO)]
class InvalidDiscordSignatureException extends HttpException
{
    public function __construct()
    {
        parent::__construct(Response::HTTP_UNAUTHORIZED, 'Invalid request signature.');
    }
}
