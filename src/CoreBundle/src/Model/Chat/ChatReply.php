<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Model\Notification\UniversalEmbed;

/**
 * The answer to a command or a button: one message, which later actions (Refresh, pages) edit in place. Overflow is
 * paged (page / pageCount), never sent as more messages.
 *
 * It may carry one image, sent as a file with the text as its caption. Only the worker sends it (the answer is never
 * serialized), and an answer with an image has no buttons: neither platform edits a file message the same way.
 */
final readonly class ChatReply
{
    public const string ERROR = 'Something went wrong, try again in a few minutes.';
    /** Added to the text when the platform refused the image and the answer goes as text only */
    public const string IMAGE_NOT_SENT = 'The image could not be sent.';

    /**
     * @param list<UniversalEmbed>   $embeds
     * @param list<list<ChatAction>> $actionRows buttons, one list per row
     */
    public function __construct(
        public ?string $text = null,
        public array $embeds = [],
        public array $actionRows = [],
        public int $page = 1,
        public int $pageCount = 1,
        public ?ChatImage $image = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * Generic answer for a failure: the error itself stays in the logs.
     */
    public static function error(string $text = self::ERROR): self
    {
        return new self('⚠️ ' . $text);
    }

    /**
     * @param list<UniversalEmbed>   $embeds
     * @param list<list<ChatAction>> $actionRows
     */
    public static function embeds(array $embeds, array $actionRows = [], ?string $text = null): self
    {
        return new self($text, $embeds, $actionRows);
    }

    /**
     * An image with a short text, its caption.
     */
    public static function image(ChatImage $image, ?string $text = null): self
    {
        return new self($text, image: $image);
    }

    public function isEmpty(): bool
    {
        return ($this->text === null || trim($this->text) === '') && $this->embeds === [] && $this->image === null;
    }
}
