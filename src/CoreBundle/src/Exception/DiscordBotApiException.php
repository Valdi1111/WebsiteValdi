<?php

namespace App\CoreBundle\Exception;

/**
 * A call of DiscordBotApiClient that failed. The message names the request, the HTTP status and the error Discord
 * gave, never the bot token.
 */
class DiscordBotApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
