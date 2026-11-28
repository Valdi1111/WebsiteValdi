<?php

namespace App\CoreBundle\Model\Chat;

/**
 * An image sent with a chat answer, as a file (Discord attachment, Telegram photo or document). Its size in pixels
 * lets each platform pick how to send it.
 */
final readonly class ChatImage
{
    /**
     * @param string  $description alt text (Discord) when not null
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public int $width,
        public int $height,
        public ?string $description = null,
    ) {
    }

    /**
     * Size of the file in bytes.
     */
    public function size(): int
    {
        return strlen($this->content);
    }
}
