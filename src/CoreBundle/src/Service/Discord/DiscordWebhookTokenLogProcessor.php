<?php

namespace App\CoreBundle\Service\Discord;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Masks the token of the Discord webhook URLs in every log record: "webhooks/{id}/{token}" becomes
 * "webhooks/{id}/***".
 *
 * The HttpClient logs each request and response with its URL (channel http_client, level info), which the worker's
 * console output (-vv) and the fingers_crossed buffer flushed on an error both write out. For the answers to the
 * interactions that URL holds the interaction token (DiscordReplySender), and for the notifier transport the webhook
 * token (DISCORD_DSN): credentials. All channels, since an exception message quoting the URL may be logged anywhere.
 * Strings only: an exception object in the context keeps its message.
 */
#[AsMonologProcessor]
final class DiscordWebhookTokenLogProcessor
{
    public const string MASK = '***';
    private const string NEEDLE = 'webhooks/';
    private const string PATTERN = '#(webhooks/\d+/)[^/?\#\s"\'<>]+#';

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = self::redact($record->message);
        $context = self::redactArray($record->context);
        if ($message === $record->message && $context === $record->context) {
            return $record;
        }

        return $record->with(message: $message, context: $context);
    }

    public static function redact(string $text): string
    {
        // Most records never mention a webhook: no regex for them
        if (!str_contains($text, self::NEEDLE)) {
            return $text;
        }

        return (string) preg_replace(self::PATTERN, '$1'.self::MASK, $text);
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redactArray($value);
            }
        }

        return $values;
    }
}
