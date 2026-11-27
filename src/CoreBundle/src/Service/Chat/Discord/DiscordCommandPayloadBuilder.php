<?php

namespace App\CoreBundle\Service\Chat\Discord;

use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandDefinition;
use App\CoreBundle\Model\Chat\ChatOption;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatSubcommandDefinition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The chat commands as Discord application commands
 * (https://discord.com/developers/docs/interactions/application-commands#application-command-object), for the bulk
 * overwrite of chat:commands:sync.
 *
 * Descriptions are in English; the Italian one, from the "chat" translation domain (keys = English descriptions), is
 * added as a localization only when it exists and differs. Every command can be installed on a server and by a user
 * (integration_types), and used in servers, in DMs with the bot and in other DMs (contexts).
 */
final class DiscordCommandPayloadBuilder
{
    public const string TRANSLATION_DOMAIN = 'chat';
    /** Discord locales besides the English of the descriptions */
    public const array LOCALES = ['it'];

    public const int TYPE_CHAT_INPUT = 1;
    public const int OPTION_SUB_COMMAND = 1;
    /** @var array<string, int> by ChatOptionType value */
    public const array OPTION_TYPES = [
        ChatOptionType::String->value => 3,
        ChatOptionType::Integer->value => 4,
        ChatOptionType::Boolean->value => 5,
    ];
    /** GUILD_INSTALL, USER_INSTALL */
    public const array INTEGRATION_TYPES = [0, 1];
    /** GUILD, BOT_DM, PRIVATE_CHANNEL */
    public const array CONTEXTS = [0, 1, 2];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param iterable<ChatCommandDefinition> $definitions
     * @return list<array<string, mixed>>
     */
    public function build(iterable $definitions): array
    {
        $commands = [];
        foreach ($definitions as $definition) {
            $command = ['name' => $definition->name, 'type' => self::TYPE_CHAT_INPUT] + $this->describe($definition->description);
            // Options or subcommands, never both (ChatCommandBuilder)
            $options = $definition->subcommands !== []
                ? array_map($this->subcommand(...), $definition->subcommands)
                : array_map($this->option(...), $definition->options);
            if ($options !== []) {
                $command['options'] = $options;
            }
            $command['integration_types'] = self::INTEGRATION_TYPES;
            $command['contexts'] = self::CONTEXTS;
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @return array<string, mixed>
     */
    private function subcommand(ChatSubcommandDefinition $subcommand): array
    {
        $payload = ['type' => self::OPTION_SUB_COMMAND, 'name' => $subcommand->name] + $this->describe($subcommand->description);
        if ($subcommand->options !== []) {
            $payload['options'] = array_map($this->option(...), $subcommand->options);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function option(ChatOption $option): array
    {
        $payload = ['type' => self::OPTION_TYPES[$option->type->value], 'name' => $option->name]
            + $this->describe($option->description)
            + ['required' => $option->required];
        if ($option->autocomplete) {
            $payload['autocomplete'] = true;
        }
        if ($option->choices !== []) {
            $payload['choices'] = array_map(
                static fn (ChatChoice $choice): array => ['name' => $choice->name, 'value' => $choice->value],
                $option->choices,
            );
        }

        return $payload;
    }

    /**
     * @return array{description: string, description_localizations?: array<string, string>}
     */
    private function describe(string $description): array
    {
        $localizations = [];
        foreach (self::LOCALES as $locale) {
            // The translator answers the key itself when there is no translation
            $translated = $this->translator->trans($description, [], self::TRANSLATION_DOMAIN, $locale);
            if ($translated !== '' && $translated !== $description) {
                // One description over the limit makes Discord refuse the whole overwrite
                $localizations[$locale] = DiscordEmbedLimiter::truncate($translated, ChatCommandBuilder::MAX_DESCRIPTION);
            }
        }

        return $localizations === []
            ? ['description' => $description]
            : ['description' => $description, 'description_localizations' => $localizations];
    }
}
