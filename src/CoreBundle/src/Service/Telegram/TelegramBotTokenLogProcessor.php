<?php

namespace App\CoreBundle\Service\Telegram;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Masks the token of the Telegram Bot API URLs in every log record: in "bot<id>:<secret>/sendMessage" the secret part
 * becomes MASK.
 *
 * Every Bot API call carries the token in its path, and the HttpClient logs each request and response with its URL
 * (channel http_client, level info), which the worker's console output (-vv) and the fingers_crossed buffer flushed on
 * an error both write out: the notifications (TELEGRAM_DSN) and the chat bot (TelegramBotApiClient) alike. The bot id
 * stays, it is public. Strings only: an exception object in the context keeps its message.
 */
#[AsMonologProcessor]
final class TelegramBotTokenLogProcessor
{
    public const string MASK = '***';
    private const string NEEDLE = '/bot';
    private const string PATTERN = '#(/bot\d+:)[A-Za-z0-9_-]+#';

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
        // Most records never mention the Bot API: no regex for them
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
