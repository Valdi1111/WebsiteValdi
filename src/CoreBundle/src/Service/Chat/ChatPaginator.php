<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Model\Chat\ChatPage;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;

/**
 * Splits a list of embeds into pages that each fit one message of the platform, by count and by characters: an answer
 * is one message, and its overflow is paged (◀ ▶), never sent as more messages.
 *
 * The characters are counted as Discord counts them, on the embed the renderer sends (icons prepended to the values),
 * so the limiter never has to cut a page. One embed is never split: an embed too large on its own fills a page alone,
 * and the limiter truncates it. Telegram (4096 characters of text) and the CLI use the same count.
 */
class ChatPaginator
{
    public function __construct(
        private readonly DiscordEmbedTransformer $embedTransformer,
    ) {
    }

    public static function maxEmbeds(ChatPlatform $platform): int
    {
        return DiscordEmbedLimiter::MAX_EMBEDS;
    }

    public static function maxCharacters(ChatPlatform $platform): int
    {
        return match ($platform) {
            ChatPlatform::Telegram => 4096,
            ChatPlatform::Discord, ChatPlatform::Cli => DiscordEmbedLimiter::MAX_TOTAL,
        };
    }

    /**
     * @param list<UniversalEmbed> $embeds
     * @param int                  $page   1-based; clamped to the pages there are (a stale button may ask for more)
     */
    public function paginate(array $embeds, ChatPlatform $platform, int $page = 1): ChatPage
    {
        $pages = [];
        $current = [];
        $characters = 0;
        foreach ($embeds as $embed) {
            $length = $this->measure($embed);
            if ($current !== [] && (count($current) >= self::maxEmbeds($platform) || $characters + $length > self::maxCharacters($platform))) {
                $pages[] = $current;
                $current = [];
                $characters = 0;
            }
            $current[] = $embed;
            $characters += $length;
        }
        if ($current !== [] || $pages === []) {
            $pages[] = $current;
        }

        $page = max(1, min($page, count($pages)));

        return new ChatPage($pages[$page - 1], $page, count($pages));
    }

    private function measure(UniversalEmbed $embed): int
    {
        return DiscordEmbedLimiter::countText($this->embedTransformer->transform($embed)->toArray());
    }
}
