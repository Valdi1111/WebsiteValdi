<?php

namespace App\Tests\CoreBundle\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Command\ChatCommandsSyncCommand;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\Discord\DiscordCommandPayloadBuilder;
use App\CoreBundle\Service\Chat\Telegram\TelegramCommandPayloadBuilder;
use App\CoreBundle\Service\Discord\DiscordBotApiClient;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\CoreBundle\Service\UserRoleResolver;
use App\Tests\CoreBundle\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * chat:commands:sync over two fixture commands, with Discord served by FakeDiscordApi.
 */
class ChatCommandsSyncCommandTest extends TestCase
{
    private const string BOT_TOKEN = 'sync-bot-token';

    private FakeDiscordApi $discord;

    protected function setUp(): void
    {
        $this->discord = new FakeDiscordApi();
    }

    /**
     * @param array<string, ChatCommandInterface> $commands
     */
    private function tester(string $botToken = self::BOT_TOKEN, ?array $commands = null): CommandTester
    {
        $commands ??= [
            'ping' => new #[AsChatCommand('ping', 'Answer <pong>')] class implements ChatCommandInterface {
                public function configure(ChatCommandBuilder $builder): void
                {
                }

                public function execute(ChatRequest $request): ChatReply
                {
                    return ChatReply::text('pong');
                }
            },
            'echo' => new #[AsChatCommand('echo', 'Repeat a text')] class implements ChatCommandInterface {
                public function configure(ChatCommandBuilder $builder): void
                {
                    $builder->addOption('text', 'The text', required: true);
                }

                public function execute(ChatRequest $request): ChatReply
                {
                    return ChatReply::text((string) $request->get('text'));
                }
            },
        ];
        $registry = new ChatCommandRegistry(
            new ServiceLocator(array_map(static fn (ChatCommandInterface $command): \Closure => static fn (): ChatCommandInterface => $command, $commands)),
            $this->createStub(UserRoleResolver::class),
        );

        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['Repeat a text' => 'Ripete un testo'], 'it', DiscordCommandPayloadBuilder::TRANSLATION_DOMAIN);

        return new CommandTester(new ChatCommandsSyncCommand(
            $registry,
            new DiscordCommandPayloadBuilder($translator),
            new DiscordBotApiClient($this->discord->client(), $botToken),
            new TelegramCommandPayloadBuilder($translator),
            // Telegram has its own tests (Telegram\Outbound\ChatCommandsSyncTelegramTest): never reached here
            new TelegramBotApiClient(new MockHttpClient(), ''),
        ));
    }

    public function testTheDescriptionWarnsThatEveryGlobalCommandIsReplaced(): void
    {
        $attribute = new \ReflectionClass(ChatCommandsSyncCommand::class)->getAttributes(AsCommand::class)[0]->newInstance();

        $this->assertStringContainsString('REPLACING every global command', $attribute->description);
    }

    public function testWithoutBotTokenNothingIsSyncedAndTheDeployGoesOn(): void
    {
        $tester = $this->tester(botToken: '');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('DISCORD_BOT_TOKEN is empty', $tester->getDisplay());
        $this->assertSame([], $this->discord->requests);
    }

    public function testDryRunPrintsThePayloadWithoutCallingDiscordEvenWithoutBotToken(): void
    {
        $tester = $this->tester(botToken: '');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));

        $this->assertSame([], $this->discord->requests);
        $this->assertSame([
            [
                'name' => 'echo',
                'type' => 1,
                'description' => 'Repeat a text',
                'description_localizations' => ['it' => 'Ripete un testo'],
                'options' => [['type' => 3, 'name' => 'text', 'description' => 'The text', 'required' => true]],
                'integration_types' => [0, 1],
                'contexts' => [0, 1, 2],
            ],
            [
                // Printed raw: the console formatter would take "<pong>" for a tag
                'name' => 'ping',
                'type' => 1,
                'description' => 'Answer <pong>',
                'integration_types' => [0, 1],
                'contexts' => [0, 1, 2],
            ],
        ], json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString("\n    {\n", $tester->getDisplay(), 'Pretty-printed');
    }

    public function testTheCommandsReplaceTheGlobalCommandsOfTheBotsApplication(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--platform' => 'discord']));

        $this->assertSame(['GET applications/@me', 'PUT applications/'.FakeDiscordApi::APPLICATION_ID.'/commands'], $this->discord->requestedPaths());
        $this->assertSame(['echo', 'ping'], array_column($this->discord->jsonBody(1), 'name'));
        $this->assertStringContainsString('2 command(s) registered on Discord', $tester->getDisplay());
    }

    public function testARefusalFailsWithDiscordsErrorAndWithoutTheToken(): void
    {
        $this->discord->commandsStatus = 400;
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('HTTP 400', $display);
        $this->assertStringContainsString('left as they were', $display);
        $this->assertStringNotContainsString(self::BOT_TOKEN, $display);
    }

    public function testAnUnauthorizedBotStopsBeforeThePut(): void
    {
        $this->discord->applicationStatus = 401;
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame(['GET applications/@me'], $this->discord->requestedPaths());
    }

    public function testAnInvalidDefinitionRegistersNothing(): void
    {
        // A command description has at least 3 characters (Telegram)
        $tester = $this->tester(commands: ['bad' => new #[AsChatCommand('bad', 'No')] class implements ChatCommandInterface {
            public function configure(ChatCommandBuilder $builder): void
            {
            }

            public function execute(ChatRequest $request): ChatReply
            {
                return ChatReply::text('');
            }
        }]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([], $this->discord->requests);
    }

    public function testAnUnknownPlatformIsInvalid(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['--platform' => 'cli']));
        $this->assertSame([], $this->discord->requests);
    }
}
