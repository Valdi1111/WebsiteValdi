<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;

/**
 * A chat answer as plain text, for chat:run: the text, then each embed (title, description, "icon name: value" fields,
 * footer), a line about the image, the buttons with the data they would send, and the page.
 */
class TextReplyRenderer
{
    public function __construct(
        private readonly ChatActionCodec $codec,
    ) {
    }

    public function render(ChatReply $reply): string
    {
        $blocks = [];
        if ($reply->text !== null && trim($reply->text) !== '') {
            $blocks[] = $reply->text;
        }
        foreach ($reply->embeds as $embed) {
            $blocks[] = $this->renderEmbed($embed);
        }
        if ($reply->image !== null) {
            $blocks[] = sprintf('[image: %s, %s, %dx%d, %d KB]', $reply->image->filename, $reply->image->contentType, $reply->image->width, $reply->image->height, intdiv($reply->image->size(), 1024));
        }
        if ($reply->actionRows !== []) {
            $blocks[] = implode("\n", array_map(
                fn (array $row): string => implode('  ', array_map($this->renderAction(...), $row)),
                $reply->actionRows,
            ));
        }
        if ($reply->pageCount > 1) {
            $blocks[] = sprintf('Page %d/%d', $reply->page, $reply->pageCount);
        }

        return implode("\n\n", array_filter($blocks, static fn (string $block): bool => $block !== ''));
    }

    private function renderEmbed(UniversalEmbed $embed): string
    {
        $lines = [];
        if ($embed->getTitle() !== null) {
            $lines[] = $embed->getTitle();
        }
        if ($embed->getDescription() !== null) {
            $lines[] = $embed->getDescription();
        }
        foreach ($embed->getFields() as $field) {
            $lines[] = sprintf('%s: %s', trim(($field->getIcon() ?? '') . ' ' . $field->getName()), $field->getValue());
        }
        $footer = array_filter([$embed->getFooterText(), $embed->getTimestamp()?->format(\DateTimeInterface::ATOM)], static fn (?string $part): bool => $part !== null && $part !== '');
        if ($footer !== []) {
            $lines[] = implode(' · ', $footer);
        }

        return implode("\n", $lines);
    }

    private function renderAction(ChatAction $action): string
    {
        try {
            $data = $this->codec->encode($action->ref);
        } catch (InvalidChatActionRefException $e) {
            // Shown rather than thrown: chat:run is where a developer finds such a button
            $data = sprintf('(invalid: %s)', $e->getMessage());
        }

        return sprintf('[%s] → %s', trim(($action->emoji ?? '') . ' ' . $action->label), $data);
    }
}
