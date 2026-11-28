<?php

namespace App\Tests\GamesBundle\Chat;

use App\CoreBundle\Model\Chat\ChatOption;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\Command\AbstractProfileChatCommand;
use App\GamesBundle\Chat\Command\PullsChatCommand;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Pull\Card\PullCardBuilderRegistry;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullCardBuilder;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullCardBuilder;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullCardBuilder;
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

    public function testPullsIsAPublicCommandForTheGamesRoleWithItsItalianDescriptions(): void
    {
        $commands = static::getContainer()->get(ChatCommandRegistry::class);
        $translator = static::getContainer()->get('translator');

        $definition = $commands->getDefinition('pulls');
        $this->assertInstanceOf(PullsChatCommand::class, $commands->get('pulls'));
        $this->assertSame('Show the image of the pulls of a game profile', $definition->description);
        $this->assertSame(AbstractProfileChatCommand::ROLE, $definition->role);
        $this->assertFalse($definition->ephemeral);
        $this->assertSame(['game', 'profile', 'banner', 'all', 'player'], array_map(static fn (ChatOption $option): string => $option->name, $definition->options));

        foreach ([
            'Show the image of the pulls of a game profile' => "Mostra l'immagine delle pull di un profilo di gioco",
            'Only this banner (all of them when empty)' => 'Solo questo banner (tutti se vuoto)',
            'Show every S pull, not only the newest ones' => 'Mostra tutte le pull S, non solo le più recenti',
            'Show the nickname and the UID' => "Mostra il nickname e l'UID",
        ] as $english => $italian) {
            $this->assertSame($italian, $translator->trans($english, [], 'chat', 'it'), $english);
        }
        foreach ($definition->options as $option) {
            $this->assertNotSame($option->description, $translator->trans($option->description, [], 'chat', 'it'), sprintf('Italian description of the option "%s"', $option->name));
        }
    }

    public function testEveryProviderHasItsPullsCardBuilder(): void
    {
        $builders = static::getContainer()->get(PullCardBuilderRegistry::class);

        $this->assertInstanceOf(HoyoversePullCardBuilder::class, $builders->find(new HoyoverseGameProfile()->setGameId(GenshinImpactService::GAME_ID)));
        $this->assertInstanceOf(GryphlinePullCardBuilder::class, $builders->find(new GryphlineGameProfile()->setGameBiz(ArknightsEndfieldService::GAME_BIZ)));
        $this->assertInstanceOf(PerfectworldPullCardBuilder::class, $builders->find(new PerfectworldGameProfile()->setGameId(NevernessToEvernessService::GAME_ID)));
        // A game without pull tracker
        $this->assertNull($builders->find(new HoyoverseGameProfile()->setGameId(HonkaiImpact3rdService::GAME_ID)));
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
