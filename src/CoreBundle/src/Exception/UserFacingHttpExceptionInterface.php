<?php

namespace App\CoreBundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Marker interface for HTTP exceptions whose message is safe and intended
 * to be exposed to end users in RFC 7807 'detail' even when kernel.debug is false.
 */
interface UserFacingHttpExceptionInterface extends HttpExceptionInterface
{
}
