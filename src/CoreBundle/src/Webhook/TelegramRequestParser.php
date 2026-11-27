<?php

namespace App\CoreBundle\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * The updates of the Telegram bot posted to /webhook/telegram (framework.webhook.routing.telegram): authenticated by
 * the secret Telegram sends back in X-Telegram-Bot-Api-Secret-Token (set by telegram:webhook:set), then each one a
 * RemoteEvent "telegram_update" handled on core_chat (TelegramRemoteEventConsumer).
 *
 * Every authenticated update becomes an event, even a kind the bot does not handle: a refused update would be sent
 * again by Telegram. An empty secret refuses everything: the webhook is not in use (polling, or Telegram disabled).
 */
final class TelegramRequestParser extends AbstractRequestParser
{
    public const string SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';
    public const string EVENT_NAME = 'telegram_update';

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        // Checked before comparing: a request without the header would equal an empty secret
        if ($secret === '') {
            throw new RejectWebhookException(Response::HTTP_NOT_FOUND, 'The Telegram webhook is disabled.');
        }
        if (!hash_equals($secret, (string) $request->headers->get(self::SECRET_HEADER))) {
            throw new RejectWebhookException(Response::HTTP_FORBIDDEN, 'Invalid secret token.');
        }

        $update = $request->toArray();
        if (!is_int($update['update_id'] ?? null)) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Not a Telegram update.');
        }

        return new RemoteEvent(self::EVENT_NAME, (string) $update['update_id'], $update);
    }
}
