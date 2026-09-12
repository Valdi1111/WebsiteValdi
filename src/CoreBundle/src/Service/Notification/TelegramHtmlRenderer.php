<?php

namespace App\CoreBundle\Service\Notification;

use App\CoreBundle\Model\Notification\UniversalEmbed;

class TelegramHtmlRenderer
{
    private const int MAX_INLINE_COLUMNS = 3;

    public function __construct(private readonly TelegramParsedown $parsedown)
    {
    }

    /**
     * @param UniversalEmbed $embed L'embed da renderizzare
     * @param bool $respectInlineLayout Se true, affianca fino a 3 campi inline con " | ".
     *                                  Se false (default), mette ogni campo su una nuova riga.
     */
    public function render(UniversalEmbed $embed, bool $respectInlineLayout = false): string
    {
        $cardLines = [];

        // Titolo in grassetto
        if ($embed->getTitle() !== null && $embed->getTitle() !== '') {
            $cardLines[] = sprintf('<b>%s</b>', $this->parsedown->toTelegramHtml($embed->getTitle()));
        }

        // Descrizione
        if ($embed->getDescription() !== null && $embed->getDescription() !== '') {
            if (!empty($cardLines)) {
                $cardLines[] = '';
            }
            $cardLines[] = $this->parsedown->toTelegramHtml($embed->getDescription());
        }

        // Fields
        $fields = $embed->getFields();
        if (!empty($fields)) {
            if (!empty($cardLines)) {
                $cardLines[] = '';
            }

            if ($respectInlineLayout) {
                $lineBuffer = [];

                foreach ($fields as $field) {
                    // Prepara il nome con l'icona per Telegram
                    $displayName = $field->getName();
                    if ($field->getIcon() !== null && $field->getIcon() !== '') {
                        $displayName = sprintf('%s %s', $field->getIcon(), $displayName);
                    }

                    $formatted = sprintf(
                        '<b>%s:</b> %s',
                        $this->parsedown->toTelegramHtml($displayName),
                        $this->parsedown->toTelegramHtml($field->getValue())
                    );

                    if ($field->isInline()) {
                        $lineBuffer[] = $formatted;

                        if (count($lineBuffer) === self::MAX_INLINE_COLUMNS) {
                            $cardLines[] = implode(' | ', $lineBuffer);
                            $lineBuffer = [];
                        }
                    } else {
                        if (!empty($lineBuffer)) {
                            $cardLines[] = implode(' | ', $lineBuffer);
                            $lineBuffer = [];
                        }
                        $cardLines[] = $formatted;
                    }
                }

                if (!empty($lineBuffer)) {
                    $cardLines[] = implode(' | ', $lineBuffer);
                }
            } else {
                foreach ($fields as $field) {
                    $name = trim($field->getName());
                    $value = trim($field->getValue());

                    if ($name === "\u{200b}" || $name === '') {
                        continue;
                    }

                    // Prepara il nome con l'icona per Telegram
                    $displayName = $name;
                    if ($field->getIcon() !== null && $field->getIcon() !== '') {
                        $displayName = sprintf('%s %s', $field->getIcon(), $displayName);
                    }

                    $cardLines[] = sprintf(
                        '<b>%s:</b> %s',
                        $this->parsedown->toTelegramHtml($displayName),
                        $this->parsedown->toTelegramHtml($value)
                    );
                }
            }
        }

        // Footer in corsivo
        if ($embed->getFooterText() !== null && $embed->getFooterText() !== '') {
            if (!empty($cardLines)) {
                $cardLines[] = '';
            }
            $cardLines[] = sprintf('<i>%s</i>', $this->parsedown->toTelegramHtml($embed->getFooterText()));
        }

        if (empty($cardLines)) {
            return '';
        }

        $body = implode("\n", $cardLines);

        // Thumbnail invisibile per l'anteprima in alto
        if ($embed->getThumbnailUrl() !== null && $embed->getThumbnailUrl() !== '') {
            $invisibleLink = sprintf('<a href="%s">&#8205;</a>', htmlspecialchars($embed->getThumbnailUrl(), ENT_QUOTES));
            return '<blockquote>' . $invisibleLink . $body . '</blockquote>';
        }

        return '<blockquote>' . $body . '</blockquote>';
    }
}