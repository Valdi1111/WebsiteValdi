<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A button under an answer.
 */
final readonly class ChatAction
{
    public function __construct(
        public string $label,
        public ChatActionRef $ref,
        public ?string $emoji = null,
        public ChatActionStyle $style = ChatActionStyle::Secondary,
    ) {
    }
}
