<?php

namespace App\CoreBundle\Service\Chat\Telegram;

use App\CoreBundle\Model\Chat\ChatCommandDefinition;
use App\CoreBundle\Service\Chat\Discord\DiscordCommandPayloadBuilder;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The chat commands as the command menu of the Telegram bot (https://core.telegram.org/bots/api#setmycommands), for
 * chat:commands:sync: the parameters of one setMyCommands call in English, the default, then one per locale of
 * DiscordCommandPayloadBuilder::LOCALES with the descriptions of the "chat" translation domain (English when missing).
 *
 * Only the private chats: Telegram has no ephemeral messages, and the bot answers there alone. The menu lists the
 * commands with their description; options and subcommands are typed after the name, so they are not part of it.
 */
final class TelegramCommandPayloadBuilder
{
    public const array SCOPE = ['type' => 'all_private_chats'];
    public const int MAX_DESCRIPTION = 256;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param iterable<ChatCommandDefinition> $definitions
     * @return list<array{commands: list<array{command: string, description: string}>, scope: array{type: string}, language_code?: string}>
     */
    public function build(iterable $definitions): array
    {
        $definitions = is_array($definitions) ? $definitions : iterator_to_array($definitions, false);

        $payloads = [['commands' => $this->commands($definitions, null), 'scope' => self::SCOPE]];
        foreach (DiscordCommandPayloadBuilder::LOCALES as $locale) {
            $payloads[] = ['commands' => $this->commands($definitions, $locale), 'scope' => self::SCOPE, 'language_code' => $locale];
        }

        return $payloads;
    }

    /**
     * @param array<ChatCommandDefinition> $definitions
     * @return list<array{command: string, description: string}>
     */
    private function commands(array $definitions, ?string $locale): array
    {
        $commands = [];
        foreach ($definitions as $definition) {
            $description = $definition->description;
            if ($locale !== null) {
                // The translator answers the key itself when there is no translation
                $translated = trim($this->translator->trans($description, [], DiscordCommandPayloadBuilder::TRANSLATION_DOMAIN, $locale));
                $description = $translated !== '' ? $translated : $description;
            }
            // One description over the limit makes Telegram refuse the whole menu
            $commands[] = ['command' => $definition->name, 'description' => DiscordEmbedLimiter::truncate($description, self::MAX_DESCRIPTION)];
        }

        return $commands;
    }
}
