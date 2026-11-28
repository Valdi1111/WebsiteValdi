<?php

namespace App\CoreBundle\Service\Chat\Discord;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionStyle;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use Psr\Log\LoggerInterface;

/**
 * A ChatReply as the message payload of Discord (https://discord.com/developers/docs/resources/message), for an
 * interaction response (type 4) and for the webhook edits and follow-ups of DiscordReplySender.
 *
 * The payload always has content, embeds, components and allowed_mentions: an edit (PATCH) leaves the omitted fields
 * as they were, so a new page or a Refresh must clear the old embeds and buttons explicitly. Mentions never ping
 * anyone: the texts may quote names typed by users.
 *
 * What exceeds the limits of Discord is cut or dropped (DiscordEmbedLimiter, at most 5 rows of 5 buttons), since
 * Discord refuses the whole message otherwise.
 */
class DiscordReplyRenderer
{
    public const int MAX_CONTENT = 2000;
    public const int MAX_ROWS = 5;
    public const int MAX_BUTTONS_PER_ROW = 5;
    public const int MAX_LABEL = 80;
    public const int MAX_ATTACHMENT_DESCRIPTION = 1024;

    public const int COMPONENT_ACTION_ROW = 1;
    public const int COMPONENT_BUTTON = 2;
    /** @var array<string, int> button style by ChatActionStyle value */
    public const array BUTTON_STYLES = [
        ChatActionStyle::Primary->value => 1,
        ChatActionStyle::Secondary->value => 2,
        ChatActionStyle::Success->value => 3,
        ChatActionStyle::Danger->value => 4,
    ];

    public function __construct(
        private readonly DiscordEmbedTransformer $embedTransformer,
        private readonly DiscordEmbedLimiter $embedLimiter,
        private readonly ChatActionCodec $actionCodec,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{content: string, embeds: list<array<string, mixed>>, components: list<array<string, mixed>>, allowed_mentions: array{parse: list<string>}, attachments?: list<array{id: int, filename: string, description?: string}>}
     */
    public function render(ChatReply $reply): array
    {
        $embeds = $this->embedLimiter->limit(array_map(
            fn (UniversalEmbed $embed): array => $this->embedTransformer->transform($embed)->toArray(),
            $reply->embeds,
        ));
        // Embeds have no page indicator of their own: the page goes under the text
        $content = self::truncate(trim(implode("\n", array_filter([
            (string) $reply->text,
            $reply->pageCount > 1 ? sprintf('Page %d/%d', $reply->page, $reply->pageCount) : '',
        ], static fn (string $line): bool => $line !== ''))));

        // Nothing to show, not even after the limits: an empty message would be refused
        if ($reply->isEmpty() || (trim($content) === '' && $embeds === [] && $reply->image === null)) {
            return self::text(ChatReply::ERROR);
        }

        $payload = [
            'content' => $content,
            'embeds' => $embeds,
            'components' => $this->renderComponents($reply->actionRows),
            'allowed_mentions' => ['parse' => []],
        ];
        if ($reply->image !== null) {
            // The file itself goes as files[0] (DiscordReplySender); on an edit, the list is every file the message keeps
            $payload['attachments'] = [array_filter([
                'id' => 0,
                'filename' => $reply->image->filename,
                'description' => $reply->image->description !== null ? mb_substr($reply->image->description, 0, self::MAX_ATTACHMENT_DESCRIPTION) : null,
            ], static fn (int|string|null $value): bool => $value !== null)];
        }

        return $payload;
    }

    /**
     * Text only, the fallback when Discord refused the full message.
     *
     * @return array{content: string, embeds: list<array<string, mixed>>, components: list<array<string, mixed>>, allowed_mentions: array{parse: list<string>}}
     */
    public function renderText(ChatReply $reply): array
    {
        $text = $reply->text === null || trim($reply->text) === '' ? ChatReply::ERROR : $reply->text;
        // The image is what was refused: the text alone must not look like the whole answer
        if ($reply->image !== null) {
            $text = $reply->text === null || trim($reply->text) === '' ? ChatReply::IMAGE_NOT_SENT : $reply->text."\n".ChatReply::IMAGE_NOT_SENT;
        }

        return self::text($text);
    }

    /**
     * @return array{content: string, embeds: list<array<string, mixed>>, components: list<array<string, mixed>>, allowed_mentions: array{parse: list<string>}}
     */
    private static function text(string $text): array
    {
        return ['content' => self::truncate($text), 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []]];
    }

    private static function truncate(string $content): string
    {
        return DiscordEmbedLimiter::truncate($content, self::MAX_CONTENT);
    }

    /**
     * @param list<list<ChatAction>> $actionRows
     * @return list<array<string, mixed>>
     */
    private function renderComponents(array $actionRows): array
    {
        $rows = [];
        // Discord refuses a message whose buttons repeat a custom_id
        $customIds = [];
        foreach ($actionRows as $actions) {
            $buttons = [];
            foreach ($actions as $action) {
                if (count($buttons) === self::MAX_BUTTONS_PER_ROW) {
                    break;
                }
                $button = $this->renderButton($action);
                if ($button === null || isset($customIds[$button['custom_id']])) {
                    continue;
                }
                $customIds[$button['custom_id']] = true;
                $buttons[] = $button;
            }
            if ($buttons === []) {
                continue;
            }

            $rows[] = ['type' => self::COMPONENT_ACTION_ROW, 'components' => $buttons];
            if (count($rows) === self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array{type: int, style: int, custom_id: string, label?: string, emoji?: array{name: string}}|null null when it cannot be shown
     */
    private function renderButton(ChatAction $action): ?array
    {
        try {
            $customId = $this->actionCodec->encode($action->ref);
        } catch (InvalidChatActionRefException $e) {
            // A programming error of the command: the answer is still worth sending without that button
            $this->logger?->error('Chat button dropped: {error}', ['error' => $e->getMessage(), 'handler' => $action->ref->handler]);

            return null;
        }

        $button = ['type' => self::COMPONENT_BUTTON, 'style' => self::BUTTON_STYLES[$action->style->value], 'custom_id' => $customId];
        $label = DiscordEmbedLimiter::truncate(trim($action->label), self::MAX_LABEL);
        if ($label !== '') {
            $button['label'] = $label;
        }
        if ($action->emoji !== null && $action->emoji !== '') {
            $button['emoji'] = ['name' => $action->emoji];
        }

        // A button shows a label, an emoji or both
        return isset($button['label']) || isset($button['emoji']) ? $button : null;
    }
}
