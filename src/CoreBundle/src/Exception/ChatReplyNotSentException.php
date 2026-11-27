<?php

namespace App\CoreBundle\Exception;

/**
 * A chat answer the platform refused or that could not reach it. The message names the platform and the HTTP status
 * only: the URL of a Discord answer holds the interaction token.
 */
class ChatReplyNotSentException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
