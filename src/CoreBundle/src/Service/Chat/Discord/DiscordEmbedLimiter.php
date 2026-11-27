<?php

namespace App\CoreBundle\Service\Chat\Discord;

/**
 * Fits rendered embeds (DiscordEmbed::toArray()) to the limits of a Discord message
 * (https://discord.com/developers/docs/resources/message#embed-object-embed-limits), beyond which Discord refuses the
 * whole message: each text is cut with "…", the fields beyond 25 and the embeds beyond 10 are dropped.
 *
 * The 6000 characters allowed across all the embeds are spent in reading order (author, title, description, fields,
 * footer, embed after embed): what no longer fits is cut, a field whose name no longer fits is dropped, and so is an
 * embed left without text. Paging (ChatReply::$page) keeps a normal answer well below; this is the safety net.
 *
 * Characters are counted as code points, as ChatCommandBuilder counts the descriptions.
 */
final class DiscordEmbedLimiter
{
    public const int MAX_EMBEDS = 10;
    public const int MAX_TOTAL = 6000;
    public const int MAX_TITLE = 256;
    public const int MAX_DESCRIPTION = 4096;
    public const int MAX_FIELDS = 25;
    public const int MAX_FIELD_NAME = 256;
    public const int MAX_FIELD_VALUE = 1024;
    public const int MAX_FOOTER = 2048;
    public const int MAX_AUTHOR = 256;
    public const string ELLIPSIS = '…';

    /**
     * Cuts a text to at most $max characters, the last one being "…" when cut.
     */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return $max <= 0 ? '' : mb_substr($text, 0, $max - 1).self::ELLIPSIS;
    }

    /**
     * @param list<array<string, mixed>> $embeds
     * @return list<array<string, mixed>>
     */
    public function limit(array $embeds): array
    {
        $budget = self::MAX_TOTAL;
        $limited = [];
        foreach (array_slice($embeds, 0, self::MAX_EMBEDS) as $embed) {
            if ($budget <= 0) {
                break;
            }

            $hadText = self::countText($embed) > 0;
            $embed = self::limitEmbed($embed, $budget);
            if ($hadText && self::countText($embed) === 0) {
                continue;
            }
            $limited[] = $embed;
        }

        return $limited;
    }

    /**
     * @param array<string, mixed> $embed
     * @return array<string, mixed>
     */
    private static function limitEmbed(array $embed, int &$budget): array
    {
        if (isset($embed['author']['name'])) {
            $embed['author']['name'] = self::spend((string) $embed['author']['name'], self::MAX_AUTHOR, $budget);
            if ($embed['author']['name'] === '') {
                unset($embed['author']);
            }
        }

        foreach (['title' => self::MAX_TITLE, 'description' => self::MAX_DESCRIPTION] as $key => $max) {
            if (isset($embed[$key])) {
                $embed[$key] = self::spend((string) $embed[$key], $max, $budget);
                if ($embed[$key] === '') {
                    unset($embed[$key]);
                }
            }
        }

        if (isset($embed['fields'])) {
            $fields = [];
            foreach (array_slice($embed['fields'], 0, self::MAX_FIELDS) as $field) {
                $name = self::truncate((string) ($field['name'] ?? ''), self::MAX_FIELD_NAME);
                // Name and value are both required: a field fits with its whole name and some of its value
                if (mb_strlen($name) >= $budget) {
                    break;
                }
                $budget -= mb_strlen($name);
                $field['name'] = $name;
                $field['value'] = self::spend((string) ($field['value'] ?? ''), self::MAX_FIELD_VALUE, $budget);
                $fields[] = $field;
            }
            if ($fields === []) {
                unset($embed['fields']);
            } else {
                $embed['fields'] = $fields;
            }
        }

        if (isset($embed['footer']['text'])) {
            $embed['footer']['text'] = self::spend((string) $embed['footer']['text'], self::MAX_FOOTER, $budget);
            if ($embed['footer']['text'] === '') {
                unset($embed['footer']);
            }
        }

        return $embed;
    }

    /**
     * Cuts a text to its own limit and to what is left of the budget, and takes it from the budget.
     */
    private static function spend(string $text, int $max, int &$budget): string
    {
        $text = self::truncate($text, min($max, $budget));
        $budget -= mb_strlen($text);

        return $text;
    }

    /**
     * Characters of an embed that count toward the 6000 of a message.
     *
     * @param array<string, mixed> $embed
     */
    public static function countText(array $embed): int
    {
        $length = mb_strlen((string) ($embed['title'] ?? ''))
            + mb_strlen((string) ($embed['description'] ?? ''))
            + mb_strlen((string) ($embed['footer']['text'] ?? ''))
            + mb_strlen((string) ($embed['author']['name'] ?? ''));
        foreach ($embed['fields'] ?? [] as $field) {
            $length += mb_strlen((string) ($field['name'] ?? '')) + mb_strlen((string) ($field['value'] ?? ''));
        }

        return $length;
    }
}
