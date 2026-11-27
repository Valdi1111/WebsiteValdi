<?php

namespace App\CoreBundle\Service\Chat\Telegram;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;

/**
 * Where a Telegram answer goes: the private chat, and the message of the pressed button when the answer replaces it
 * (ChatReplyMode::Replace). Without a message, an answer is a new message.
 */
final class TelegramTarget
{
    public const string CHAT_ID = 'chat_id';
    public const string MESSAGE_ID = 'message_id';

    public static function create(string $chatId, ?string $messageId = null): ChatReplyTarget
    {
        return new ChatReplyTarget(ChatPlatform::Telegram, array_filter([
            self::CHAT_ID => $chatId,
            self::MESSAGE_ID => $messageId,
        ], static fn (?string $value): bool => $value !== null));
    }

    public static function chatId(ChatReplyTarget $target): ?string
    {
        return $target->get(self::CHAT_ID);
    }

    public static function messageId(ChatReplyTarget $target): ?string
    {
        return $target->get(self::MESSAGE_ID);
    }
}
