<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatActionRef;

/**
 * A button's ChatActionRef as the platforms carry it (Discord custom_id, Telegram callback_data): "handler:arg1:arg2".
 *
 * The limit is the strictest of the platforms, 64 bytes (Telegram; Discord allows 100 characters), counted in bytes:
 * a multibyte argument uses more than one.
 */
class ChatActionCodec
{
    public const int MAX_BYTES = 64;
    public const string SEPARATOR = ':';

    /**
     * @throws InvalidChatActionRefException when the handler is empty, a part holds ":" or the result is too long: a
     *                                       programming error of the command that built the button
     */
    public function encode(ChatActionRef $ref): string
    {
        if ($ref->handler === '' || str_contains($ref->handler, self::SEPARATOR)) {
            throw new InvalidChatActionRefException(sprintf('Chat action handler "%s": not empty and without "%s".', $ref->handler, self::SEPARATOR));
        }
        foreach ($ref->args as $index => $arg) {
            if (str_contains((string) $arg, self::SEPARATOR)) {
                throw new InvalidChatActionRefException(sprintf('Argument %d of chat action "%s" holds "%s".', $index, $ref->handler, self::SEPARATOR));
            }
        }

        $data = implode(self::SEPARATOR, [$ref->handler, ...$ref->args]);
        if (strlen($data) > self::MAX_BYTES) {
            throw new InvalidChatActionRefException(sprintf('Chat action "%s" takes %d bytes, at most %d.', $ref->handler, strlen($data), self::MAX_BYTES));
        }

        return $data;
    }

    /**
     * @throws InvalidChatActionRefException when the data was not made by encode(): the platform sent it, so it may be
     *                                       anything
     */
    public function decode(string $data): ChatActionRef
    {
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            throw new InvalidChatActionRefException('Chat action data: empty or too long.');
        }

        $args = explode(self::SEPARATOR, $data);
        $handler = array_shift($args);
        if ($handler === '') {
            throw new InvalidChatActionRefException('Chat action data without a handler.');
        }

        return new ChatActionRef($handler, $args);
    }
}
