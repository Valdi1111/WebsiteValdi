<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Model\Notification\UniversalEmbed;

/**
 * The answer to a command or a button: one message, which later actions (Refresh, pages) edit in place. Overflow is
 * paged (page / pageCount), never sent as more messages.
 */
final readonly class ChatReply
{
    public const string ERROR = 'Something went wrong, try again in a few minutes.';

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

    public function isEmpty(): bool
    {
        return ($this->text === null || trim($this->text) === '') && $this->embeds === [];
    }
}
