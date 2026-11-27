<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Exception\InvalidChatCommandException;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatOptionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChatCommandBuilderTest extends TestCase
{
    public function testDefinitionWithOptionsAndMetadata(): void
    {
        $definition = new ChatCommandBuilder('stamina', 'Show your stamina', 'ROLE_GAMES', false)
            ->addOption('game', 'Only this game', required: true, autocomplete: true)
            ->addOption('fresh', 'Skip the cache', ChatOptionType::Boolean)
            ->addOption('mode', 'Layout', choices: [new ChatChoice('Compact', 'compact')])
            ->getDefinition();

        $this->assertSame('stamina', $definition->name);
        $this->assertSame('Show your stamina', $definition->description);
        $this->assertSame('ROLE_GAMES', $definition->role);
        $this->assertFalse($definition->ephemeral);
        $this->assertSame(['game', 'fresh', 'mode'], array_map(static fn ($option): string => $option->name, $definition->options));
        $this->assertTrue($definition->options[0]->required);
        $this->assertTrue($definition->options[0]->autocomplete);
        $this->assertSame(ChatOptionType::Boolean, $definition->options[1]->type);
        $this->assertSame([], $definition->subcommands);
    }

    public function testDefinitionWithSubcommands(): void
    {
        $definition = new ChatCommandBuilder('remind', 'Manage your reminders')
            ->addSubcommand('stamina', 'When the stamina reaches a value', static function (ChatCommandBuilder $builder): void {
                $builder->addOption('value', 'Stamina to reach', ChatOptionType::Integer, required: true);
            })
            ->addSubcommand('list', 'Your reminders')
            ->getDefinition();

        $this->assertSame(['stamina', 'list'], array_map(static fn ($subcommand): string => $subcommand->name, $definition->subcommands));
        $this->assertSame('value', $definition->getSubcommand('stamina')?->options[0]->name);
        $this->assertSame([], $definition->getSubcommand('list')?->options);
        $this->assertNull($definition->getSubcommand('cancel'));
        $this->assertNull($definition->role);
        $this->assertTrue($definition->ephemeral);
    }

    /**
     * @return iterable<string, array{\Closure(): ChatCommandBuilder, string}>
     */
    public static function invalidCommands(): iterable
    {
        $many = static fn (string $prefix): array => array_map(static fn (int $i): string => $prefix . $i, range(1, ChatCommandBuilder::MAX_ITEMS + 1));

        yield 'uppercase command name' => [static fn () => new ChatCommandBuilder('Stamina', 'Show your stamina'), 'Command "Stamina": a name'];
        yield 'empty command name' => [static fn () => new ChatCommandBuilder('', 'Show your stamina'), 'Command "": a name'];
        yield 'command name over 32' => [static fn () => new ChatCommandBuilder(str_repeat('a', 33), 'Show your stamina'), 'a name has 1 to 32'];
        yield 'dash in a command name' => [static fn () => new ChatCommandBuilder('my-command', 'Show your stamina'), 'Command "my-command"'];
        yield 'command description under 3' => [static fn () => new ChatCommandBuilder('stamina', 'Hi'), 'Command "stamina": a description has 3 to 100'];
        yield 'command description over 100' => [static fn () => new ChatCommandBuilder('stamina', str_repeat('é', 101)), 'a description has 3 to 100'];
        yield 'option name' => [static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('Game', 'A game'), 'Option "Game" of Command "stamina"'];
        yield 'empty option description' => [static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', ''), 'a description has 1 to 100'];
        yield 'required after optional' => [
            static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', 'A game')->addOption('uid', 'A profile', required: true),
            'Option "uid" of Command "stamina" is required after an optional one',
        ];
        yield 'choices and autocomplete' => [
            static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', 'A game', autocomplete: true, choices: [new ChatChoice('GI', 'gi')]),
            'has both choices and autocomplete',
        ];
        yield 'too many choices' => [
            static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', 'A game', choices: array_map(static fn (string $name): ChatChoice => new ChatChoice($name, $name), $many('g'))),
            'has more than 25 choices',
        ];
        yield 'empty choice name' => [
            static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', 'A game', choices: [new ChatChoice('', 'gi')]),
            'A choice of Option "game"',
        ];
        yield 'too many options' => [
            static function () use ($many): ChatCommandBuilder {
                $builder = new ChatCommandBuilder('stamina', 'Show it');
                foreach ($many('o') as $name) {
                    $builder->addOption($name, 'An option');
                }

                return $builder;
            },
            'Command "stamina" has more than 25 options',
        ];
        yield 'repeated option' => [
            static fn () => new ChatCommandBuilder('stamina', 'Show it')->addOption('game', 'A game')->addOption('game', 'Again'),
            'Command "stamina" repeats "game"',
        ];
        yield 'options and subcommands' => [
            static fn () => new ChatCommandBuilder('remind', 'Reminders')->addOption('game', 'A game')->addSubcommand('list', 'Your reminders'),
            'Command "remind" has both options and subcommands',
        ];
        yield 'too many subcommands' => [
            static function () use ($many): ChatCommandBuilder {
                $builder = new ChatCommandBuilder('remind', 'Reminders');
                foreach ($many('s') as $name) {
                    $builder->addSubcommand($name, 'A subcommand');
                }

                return $builder;
            },
            'Command "remind" has more than 25 subcommands',
        ];
        yield 'repeated subcommand' => [
            static fn () => new ChatCommandBuilder('remind', 'Reminders')->addSubcommand('list', 'Your reminders')->addSubcommand('list', 'Again'),
            'Command "remind" repeats "list"',
        ];
    }

    /**
     * @param \Closure(): ChatCommandBuilder $builder
     */
    #[DataProvider('invalidCommands')]
    public function testInvalidDefinitionIsRefused(\Closure $builder, string $message): void
    {
        $this->expectException(InvalidChatCommandException::class);
        $this->expectExceptionMessage($message);

        $builder()->getDefinition();
    }

    /**
     * Subcommands are checked when added: their builder is not kept.
     *
     * @return iterable<string, array{\Closure(): void, string}>
     */
    public static function invalidSubcommands(): iterable
    {
        yield 'subcommand name' => [static fn () => new ChatCommandBuilder('remind', 'Reminders')->addSubcommand('List', 'Your reminders'), 'Subcommand "List": a name'];
        yield 'subcommand description' => [static fn () => new ChatCommandBuilder('remind', 'Reminders')->addSubcommand('list', ''), 'Subcommand "list": a description has 1 to 100'];
        yield 'option of a subcommand' => [
            static fn () => new ChatCommandBuilder('remind', 'Reminders')->addSubcommand('stamina', 'Stamina', static function (ChatCommandBuilder $builder): void {
                $builder->addOption('value', 'A value')->addOption('game', 'A game', required: true);
            }),
            'Option "game" of Subcommand "stamina" is required after an optional one',
        ];
        yield 'nested subcommands' => [
            static fn () => new ChatCommandBuilder('remind', 'Reminders')->addSubcommand('stamina', 'Stamina', static function (ChatCommandBuilder $builder): void {
                $builder->addSubcommand('deeper', 'Too deep');
            }),
            'Subcommand "deeper" of "stamina": subcommands cannot be nested',
        ];
    }

    /**
     * @param \Closure(): void $add
     */
    #[DataProvider('invalidSubcommands')]
    public function testInvalidSubcommandIsRefusedWhenAdded(\Closure $add, string $message): void
    {
        $this->expectException(InvalidChatCommandException::class);
        $this->expectExceptionMessage($message);

        $add();
    }
}
