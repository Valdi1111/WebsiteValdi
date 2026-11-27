<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandDefinition;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Service\Chat\Discord\DiscordCommandPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The application commands sent to Discord for a fixed set of definitions, Italian descriptions from an array
 * translator of the "chat" domain.
 */
class DiscordCommandPayloadBuilderTest extends TestCase
{
    /**
     * @param array<string, string> $italian
     */
    private static function builder(array $italian): DiscordCommandPayloadBuilder
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', $italian, 'it', DiscordCommandPayloadBuilder::TRANSLATION_DOMAIN);
        // Another domain must not be read
        $translator->addResource('array', ['Show your stamina' => 'Wrong domain'], 'it', 'messages');

        return new DiscordCommandPayloadBuilder($translator);
    }

    /**
     * @return list<ChatCommandDefinition>
     */
    private static function definitions(): array
    {
        $stamina = new ChatCommandBuilder('stamina', 'Show your stamina')
            ->addOption('game', 'The game', ChatOptionType::String, required: true, choices: [new ChatChoice('Genshin Impact', 'gi'), new ChatChoice('Honkai: Star Rail', 'hsr')])
            ->addOption('profile', 'Profile', autocomplete: true)
            ->addOption('count', 'How many', ChatOptionType::Integer)
            ->addOption('silent', 'Only for you', ChatOptionType::Boolean);

        $remind = new ChatCommandBuilder('remind', 'Manage your reminders', ephemeral: false)
            ->addSubcommand('stamina', 'Remind me of the stamina', static function (ChatCommandBuilder $builder): void {
                $builder->addOption('threshold', 'Stamina to reach', ChatOptionType::Integer, required: true);
            })
            ->addSubcommand('list', 'List your reminders');

        return [
            new ChatCommandBuilder('help', 'List the commands you can use')->getDefinition(),
            $stamina->getDefinition(),
            $remind->getDefinition(),
        ];
    }

    public function testDefinitionsBecomeGlobalSlashCommandsWithTheItalianDescriptionsThatDiffer(): void
    {
        $builder = self::builder([
            'List the commands you can use' => 'Elenca i comandi che puoi usare',
            'Show your stamina' => 'Mostra la tua stamina',
            'The game' => 'Il gioco',
            // Same as the English: no localization
            'Profile' => 'Profile',
            'Remind me of the stamina' => 'Ricordami la stamina',
            // Over the 100 characters of Discord: cut, or the whole overwrite would be refused
            'Stamina to reach' => str_repeat('s', 120),
        ]);

        $commonTail = ['integration_types' => [0, 1], 'contexts' => [0, 1, 2]];
        $this->assertSame([
            [
                'name' => 'help',
                'type' => 1,
                'description' => 'List the commands you can use',
                'description_localizations' => ['it' => 'Elenca i comandi che puoi usare'],
            ] + $commonTail,
            [
                'name' => 'stamina',
                'type' => 1,
                'description' => 'Show your stamina',
                'description_localizations' => ['it' => 'Mostra la tua stamina'],
                'options' => [
                    [
                        'type' => 3,
                        'name' => 'game',
                        'description' => 'The game',
                        'description_localizations' => ['it' => 'Il gioco'],
                        'required' => true,
                        'choices' => [['name' => 'Genshin Impact', 'value' => 'gi'], ['name' => 'Honkai: Star Rail', 'value' => 'hsr']],
                    ],
                    ['type' => 3, 'name' => 'profile', 'description' => 'Profile', 'required' => false, 'autocomplete' => true],
                    ['type' => 4, 'name' => 'count', 'description' => 'How many', 'required' => false],
                    ['type' => 5, 'name' => 'silent', 'description' => 'Only for you', 'required' => false],
                ],
            ] + $commonTail,
            [
                'name' => 'remind',
                'type' => 1,
                'description' => 'Manage your reminders',
                'options' => [
                    [
                        'type' => 1,
                        'name' => 'stamina',
                        'description' => 'Remind me of the stamina',
                        'description_localizations' => ['it' => 'Ricordami la stamina'],
                        'options' => [[
                            'type' => 4,
                            'name' => 'threshold',
                            'description' => 'Stamina to reach',
                            'description_localizations' => ['it' => str_repeat('s', 99).'…'],
                            'required' => true,
                        ]],
                    ],
                    ['type' => 1, 'name' => 'list', 'description' => 'List your reminders'],
                ],
            ] + $commonTail,
        ], $builder->build(self::definitions()));
    }

    public function testWithoutTranslationsThereAreNoLocalizations(): void
    {
        $commands = self::builder([])->build(self::definitions());

        $this->assertStringNotContainsString('description_localizations', (string) json_encode($commands));
    }

    public function testNoDefinitionsIsAnEmptyList(): void
    {
        $this->assertSame([], self::builder([])->build([]));
    }
}
