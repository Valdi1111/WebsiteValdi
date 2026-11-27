<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Model\Notification\UniversalEmbed;

/**
 * One page of embeds that fits a single message of the platform (ChatPaginator).
 */
final readonly class ChatPage
{
    /**
     * @param list<UniversalEmbed> $embeds
     */
    public function __construct(
        public array $embeds,
        public int $page,
        public int $pageCount,
    ) {
    }
}
