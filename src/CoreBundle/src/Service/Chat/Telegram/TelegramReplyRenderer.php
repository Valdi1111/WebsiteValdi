<?php

namespace App\CoreBundle\Service\Chat\Telegram;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use Psr\Log\LoggerInterface;

/**
 * A ChatReply as the parameters of sendMessage / editMessageText (https://core.telegram.org/bots/api#sendmessage),
 * without the chat and message ids, for TelegramReplySender.
 *
 * The text is HTML: the reply's own text escaped (it may echo what the user typed), then each embed through the
 * notifier's TelegramHtmlRenderer, then "Page x/y". The keyboard is always sent, empty when there are no buttons: an
 * edit keeps the old buttons otherwise. Link previews are off: one answer may hold the thumbnails of several embeds.
 *
 * Telegram refuses a text over 4096 characters after the entity parsing, counted in UTF-16 code units (an emoji
 * beyond the BMP takes 2). ChatPaginator measures the embeds the same way (visibleLength()), so a paged answer always
 * fits; when it does not (a long text, one embed too large on its own), the text is cut as plain escaped text: no tag
 * or entity can be broken, at the price of the formatting of that one answer.
 */
class TelegramReplyRenderer
{
    public const int MAX_TEXT = 4096;
    /** Telegram allows 8 buttons per row: longer rows wrap */
    public const int MAX_BUTTONS_PER_ROW = 8;
    public const string PARSE_MODE = 'HTML';
    /** Between the text, the embeds and the page line */
    public const string SEPARATOR = "\n\n";
    public const string ELLIPSIS = '…';
    /**
     * One field per line, as the notifications: " | " rows of inline fields wrap badly on a phone, and only this layout
     * skips the empty fields that complete a row on Discord (EndgamesPresenter::FILLER).
     */
    public const bool INLINE_LAYOUT = false;

    public function __construct(
        private readonly TelegramHtmlRenderer $htmlRenderer,
        private readonly ChatActionCodec $actionCodec,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{text: string, parse_mode: string, link_preview_options: array{is_disabled: bool}, reply_markup: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    public function render(ChatReply $reply): array
    {
        $blocks = [];
        if ($reply->text !== null && trim($reply->text) !== '') {
            $blocks[] = self::escape($reply->text);
        }
        foreach ($reply->embeds as $embed) {
            $html = $this->renderEmbed($embed);
            if (trim(self::toPlain($html)) !== '') {
                $blocks[] = $html;
            }
        }

        // Nothing to show (an embed with only a color renders to nothing): an empty text would be refused
        if ($blocks === []) {
            return ['text' => self::escape(ChatReply::ERROR), 'parse_mode' => self::PARSE_MODE] + self::extras([]);
        }

        $tail = self::tail($reply);
        $text = implode(self::SEPARATOR, $blocks).$tail;
        if (self::visibleLength($text) > self::MAX_TEXT) {
            // Last resort, see the class comment: the page line stays, so that the buttons still make sense
            $text = self::escape(self::cut(self::toPlain(implode(self::SEPARATOR, $blocks)), self::MAX_TEXT - self::utf16Length($tail))).$tail;
        }

        return ['text' => $text, 'parse_mode' => self::PARSE_MODE] + self::extras($this->renderKeyboard($reply->actionRows));
    }

    /**
     * The same answer without any markup and without parse_mode, the fallback when Telegram could not parse the HTML:
     * shown as it is, so not escaped.
     *
     * @return array{text: string, link_preview_options: array{is_disabled: bool}, reply_markup: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    public function renderPlain(ChatReply $reply): array
    {
        $blocks = [];
        if ($reply->text !== null && trim($reply->text) !== '') {
            $blocks[] = $reply->text;
        }
        foreach ($reply->embeds as $embed) {
            $plain = trim(self::toPlain($this->renderEmbed($embed)));
            if ($plain !== '') {
                $blocks[] = $plain;
            }
        }
        if ($blocks === []) {
            return ['text' => ChatReply::ERROR] + self::extras([]);
        }

        $tail = self::tail($reply);
        $text = implode(self::SEPARATOR, $blocks);
        if (self::utf16Length($text.$tail) > self::MAX_TEXT) {
            $text = self::cut($text, self::MAX_TEXT - self::utf16Length($tail));
        }

        return ['text' => $text.$tail] + self::extras($this->renderKeyboard($reply->actionRows));
    }

    /**
     * One embed as it goes in the text, also for ChatPaginator to measure it.
     */
    public function renderEmbed(UniversalEmbed $embed): string
    {
        return $this->htmlRenderer->render($embed, self::INLINE_LAYOUT);
    }

    /**
     * Length of an HTML text as Telegram counts it against MAX_TEXT: the text left by the entity parsing (tags removed,
     * entities decoded), in UTF-16 code units.
     */
    public static function visibleLength(string $html): int
    {
        return self::utf16Length(self::toPlain($html));
    }

    /**
     * The text of an HTML text as Telegram parses it: tags removed, then (decoding first would turn "&lt;b&gt;" into a
     * tag) only the entities Telegram decodes, the 4 named ones and the numeric ones; any other stays as it is written.
     */
    private static function toPlain(string $html): string
    {
        return (string) preg_replace_callback(
            '/&(?:(lt|gt|amp|quot)|#([0-9]{1,7})|#[xX]([0-9a-fA-F]{1,6}));/',
            static fn (array $entity): string => match (true) {
                $entity[1] !== null => ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"'][$entity[1]],
                $entity[2] !== null => mb_chr((int) $entity[2], 'UTF-8') ?: "\u{FFFD}",
                default => mb_chr((int) hexdec((string) $entity[3]), 'UTF-8') ?: "\u{FFFD}",
            },
            strip_tags($html),
            flags: \PREG_UNMATCHED_AS_NULL,
        );
    }

    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Telegram's HTML needs only <, > and & escaped; quotes stay readable in the request.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Cuts a plain text to at most $max UTF-16 code units, "…" included, between two code points.
     */
    private static function cut(string $text, int $max): string
    {
        $max -= self::utf16Length(self::ELLIPSIS);
        $length = 0;
        $kept = '';
        foreach (mb_str_split($text) as $character) {
            $length += mb_ord($character) > 0xFFFF ? 2 : 1;
            if ($length > $max) {
                break;
            }
            $kept .= $character;
        }

        return rtrim($kept).self::ELLIPSIS;
    }

    private static function tail(ChatReply $reply): string
    {
        return $reply->pageCount > 1 ? self::SEPARATOR.sprintf('Page %d/%d', $reply->page, $reply->pageCount) : '';
    }

    /**
     * @param list<list<array{text: string, callback_data: string}>> $keyboard
     * @return array{link_preview_options: array{is_disabled: bool}, reply_markup: array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}}
     */
    private static function extras(array $keyboard): array
    {
        return ['link_preview_options' => ['is_disabled' => true], 'reply_markup' => ['inline_keyboard' => $keyboard]];
    }

    /**
     * @param list<list<ChatAction>> $actionRows
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private function renderKeyboard(array $actionRows): array
    {
        $keyboard = [];
        // Two buttons sending the same data would be one action twice
        $seen = [];
        foreach ($actionRows as $actions) {
            $buttons = [];
            foreach ($actions as $action) {
                $button = $this->renderButton($action);
                if ($button === null || isset($seen[$button['callback_data']])) {
                    continue;
                }
                $seen[$button['callback_data']] = true;
                $buttons[] = $button;
            }
            array_push($keyboard, ...array_chunk($buttons, self::MAX_BUTTONS_PER_ROW));
        }

        return $keyboard;
    }

    /**
     * @return array{text: string, callback_data: string}|null null when it cannot be shown
     */
    private function renderButton(ChatAction $action): ?array
    {
        try {
            $data = $this->actionCodec->encode($action->ref);
        } catch (InvalidChatActionRefException $e) {
            // A programming error of the command: the answer is still worth sending without that button
            $this->logger?->error('Chat button dropped: {error}', ['error' => $e->getMessage(), 'handler' => $action->ref->handler]);

            return null;
        }

        // Telegram buttons have no emoji of their own: it goes before the label
        $text = trim(($action->emoji ?? '').' '.trim($action->label));

        return $text !== '' ? ['text' => $text, 'callback_data' => $data] : null;
    }
}
