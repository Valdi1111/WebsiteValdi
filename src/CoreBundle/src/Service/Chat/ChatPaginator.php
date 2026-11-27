<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Model\Chat\ChatPage;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplyRenderer;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use App\CoreBundle\Service\Notification\TelegramParsedown;

/**
 * Splits a list of embeds into pages that each fit one message of the platform, by count and by characters: an answer
 * is one message, and its overflow is paged (◀ ▶), never sent as more messages.
 *
 * The characters are counted as the platform counts them, on what its renderer sends, so that the renderer never has
 * to cut a page:
 * - Discord (and the CLI): the text of the embed DiscordReplyRenderer sends (icons prepended to the values), against
 *   the 6000 characters of a message;
 * - Telegram: the visible text of the embed as TelegramReplyRenderer renders it (tags removed, entities decoded, UTF-16
 *   code units) plus its separator, against the 4096 of a message less the page line.
 *
 * One embed is never split: an embed too large on its own fills a page alone, and the renderer truncates it.
 */
class ChatPaginator
{
    /**
     * Telegram units kept for the page line: "Page 9999/9999". Each embed counts the separator before it, the first
     * one's paying for the separator before the page line.
     */
    public const int TELEGRAM_PAGE_LINE = 14;

    public function __construct(
        private readonly DiscordEmbedTransformer $embedTransformer,
        private readonly TelegramReplyRenderer $telegramRenderer = new TelegramReplyRenderer(new TelegramHtmlRenderer(new TelegramParsedown()), new ChatActionCodec()),
    ) {
    }

    public static function maxEmbeds(ChatPlatform $platform): int
    {
        return DiscordEmbedLimiter::MAX_EMBEDS;
    }

    /**
     * Characters of one message of the platform.
     */
    public static function maxCharacters(ChatPlatform $platform): int
    {
        return match ($platform) {
            ChatPlatform::Telegram => TelegramReplyRenderer::MAX_TEXT,
            ChatPlatform::Discord, ChatPlatform::Cli => DiscordEmbedLimiter::MAX_TOTAL,
        };
    }

    /**
     * @param list<UniversalEmbed> $embeds
     * @param int                  $page   1-based; clamped to the pages there are (a stale button may ask for more)
     */
    public function paginate(array $embeds, ChatPlatform $platform, int $page = 1): ChatPage
    {
        $budget = self::budget($platform);
        $pages = [];
        $current = [];
        $characters = 0;
        foreach ($embeds as $embed) {
            $length = $this->measure($embed, $platform);
            if ($current !== [] && (count($current) >= self::maxEmbeds($platform) || $characters + $length > $budget)) {
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

    /**
     * Characters the embeds of one page may take, as measure() counts them.
     */
    private static function budget(ChatPlatform $platform): int
    {
        return match ($platform) {
            ChatPlatform::Telegram => self::maxCharacters($platform) - self::TELEGRAM_PAGE_LINE,
            ChatPlatform::Discord, ChatPlatform::Cli => self::maxCharacters($platform),
        };
    }

    private function measure(UniversalEmbed $embed, ChatPlatform $platform): int
    {
        return match ($platform) {
            ChatPlatform::Telegram => TelegramReplyRenderer::visibleLength($this->telegramRenderer->renderEmbed($embed)) + mb_strlen(TelegramReplyRenderer::SEPARATOR),
            ChatPlatform::Discord, ChatPlatform::Cli => DiscordEmbedLimiter::countText($this->embedTransformer->transform($embed)->toArray()),
        };
    }
}
