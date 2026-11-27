<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Model\Chat\ChatOption;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\Command\AbstractProfileChatCommand;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The game chat commands as the container registers them, with the real providers and games: their definitions and
 * Italian descriptions, and buttons that fit the 64 bytes of Telegram whatever they carry.
 */
class GamesChatWiringTest extends KernelTestCase
{
    /** @var array<string, array{string, string}> name => English and Italian description */
    private const array COMMANDS = [
        'status' => ['Show the status of your game profiles', 'Mostra lo stato dei tuoi profili di gioco'],
        'stamina' => ['Show the stamina of your game profiles', 'Mostra la stamina dei tuoi profili di gioco'],
        'dailies' => ['Show the daily activities of your game profiles', 'Mostra le attività giornaliere dei tuoi profili di gioco'],
        'weeklies' => ['Show the weekly activities of your game profiles', 'Mostra le attività settimanali dei tuoi profili di gioco'],
        'endgames' => ['Show the endgame modes of your game profiles', 'Mostra le modalità endgame dei tuoi profili di gioco'],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testCommandsAreRegisteredForTheGamesRoleWithTheirItalianDescriptions(): void
    {
        $commands = static::getContainer()->get(ChatCommandRegistry::class);
        $translator = static::getContainer()->get('translator');

        foreach (self::COMMANDS as $name => [$english, $italian]) {
            $definition = $commands->getDefinition($name);
            $this->assertSame($english, $definition->description, $name);
            $this->assertSame(AbstractProfileChatCommand::ROLE, $definition->role, $name);
            $this->assertTrue($definition->ephemeral, $name);
            $this->assertSame([], $definition->subcommands, $name);
            $this->assertSame(
                [[AbstractProfileChatCommand::OPTION_GAME, false, true], [AbstractProfileChatCommand::OPTION_PROFILE, false, true]],
                array_map(static fn (ChatOption $option): array => [$option->name, $option->required, $option->autocomplete], $definition->options),
                $name,
            );
            $this->assertSame($italian, $translator->trans($english, [], 'chat', 'it'), $name);

            $command = $commands->get($name);
            $this->assertInstanceOf(AbstractProfileChatCommand::class, $command, $name);
            $this->assertSame($name, $command->getName());
            $this->assertTrue($command->canRefresh(), $name);
        }
        $this->assertSame('Solo i profili di questo gioco', $translator->trans('Only the profiles of this game', [], 'chat', 'it'));
        $this->assertSame('Solo questo profilo', $translator->trans('Only this profile', [], 'chat', 'it'));
    }

    /**
     * The longest command name, page 999, a Refresh, and the longest game slug or provider key of the real bundles
     * with the largest profile id a reference holds.
     */
    public function testButtonsFitTheButtonDataInTheWorstCase(): void
    {
        $commands = static::getContainer()->get(ChatCommandRegistry::class);
        $registry = static::getContainer()->get(GameProfileRegistry::class);
        $codec = static::getContainer()->get(ChatActionCodec::class);

        $names = array_values(array_filter($commands->getNames(), static fn (string $name): bool => $commands->get($name) instanceof AbstractProfileChatCommand));
        $this->assertContains('endgames', $names);
        $games = $registry->getGames();
        $this->assertNotEmpty($games);

        $longest = static fn (array $values): string => array_reduce($values, static fn (string $carry, string $value): string => strlen($value) > strlen($carry) ? $value : $carry, '');
        $name = $longest($names);
        $slug = $longest(array_map(static fn (GameDescriptor $game): string => $game->slug, $games));
        $provider = $longest(array_map(strval(...), array_keys($registry->getProviders())));
        $game = array_find($games, static fn (GameDescriptor $candidate): bool => $candidate->slug === $slug);

        $byGame = $codec->encode(GamesChatAction::ref($name, 999, true, new ChatProfileSelection($game, null, [], [])));
        $byProfile = $codec->encode(GamesChatAction::ref($name, 999, true, new ChatProfileSelection($game, new GameProfileRef($provider, 999_999_999_999_999_999), [], [])));

        // encode() throws beyond the limit; asserted anyway, with what was built
        $this->assertLessThanOrEqual(ChatActionCodec::MAX_BYTES, strlen($byGame), $byGame);
        $this->assertLessThanOrEqual(ChatActionCodec::MAX_BYTES, strlen($byProfile), $byProfile);
        $this->assertSame(sprintf('games:%s:999:1::%s:999999999999999999', $name, $provider), $byProfile);
        // The reference a selection rebuilds from, as GameProfileRef parses it
        $this->assertNotNull(GameProfileRef::tryFromString($provider . ':999999999999999999'));
    }
}
