<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

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
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * chat:commands:sync --platform=telegram over fixture commands, with the Bot API served by FakeTelegramApi: the menu
 * of the private chats in English and in Italian, never Discord.
 */
class ChatCommandsSyncTelegramTest extends TestCase
{
    private const string DSN = 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default';

    private FakeTelegramApi $telegram;
    private FakeDiscordApi $discord;

    protected function setUp(): void
    {
        $this->telegram = new FakeTelegramApi(new MockClock('2026-11-27 21:00:00'));
        $this->discord = new FakeDiscordApi();
    }

    private function tester(string $dsn = self::DSN): CommandTester
    {
        $commands = [
            'ping' => new #[AsChatCommand('ping', 'Answer <pong>')] class implements ChatCommandInterface {
                public function configure(ChatCommandBuilder $builder): void
                {
                }

                public function execute(ChatRequest $request): ChatReply
                {
                    return ChatReply::text('pong');
                }
            },
            'remind' => new #[AsChatCommand('remind', 'Manage your reminders')] class implements ChatCommandInterface {
                public function configure(ChatCommandBuilder $builder): void
                {
                    $builder->addSubcommand('list', 'List your reminders');
                    $builder->addSubcommand('cancel', 'Cancel a reminder', static function (ChatCommandBuilder $sub): void {
                        $sub->addOption('id', 'The reminder', required: true);
                    });
                }

                public function execute(ChatRequest $request): ChatReply
                {
                    return ChatReply::text('ok');
                }
            },
        ];
        $registry = new ChatCommandRegistry(
            new ServiceLocator(array_map(static fn (ChatCommandInterface $command): \Closure => static fn (): ChatCommandInterface => $command, $commands)),
            $this->createStub(UserRoleResolver::class),
        );

        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['Manage your reminders' => 'Gestisci i tuoi <reminder>', 'List your reminders' => 'Elenca i tuoi reminder'], 'it', DiscordCommandPayloadBuilder::TRANSLATION_DOMAIN);

        return new CommandTester(new ChatCommandsSyncCommand(
            $registry,
            new DiscordCommandPayloadBuilder($translator),
            new DiscordBotApiClient($this->discord->client(), 'discord-test-token'),
            new TelegramCommandPayloadBuilder($translator),
            new TelegramBotApiClient($this->telegram->client(FakeTelegramApi::BOT_API), $dsn),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function menus(): array
    {
        return [
            [
                'commands' => [
                    ['command' => 'ping', 'description' => 'Answer <pong>'],
                    // Subcommands are typed after the name: the menu has the command alone
                    ['command' => 'remind', 'description' => 'Manage your reminders'],
                ],
                'scope' => ['type' => 'all_private_chats'],
            ],
            [
                'commands' => [
                    // No translation: English
                    ['command' => 'ping', 'description' => 'Answer <pong>'],
                    ['command' => 'remind', 'description' => 'Gestisci i tuoi <reminder>'],
                ],
                'scope' => ['type' => 'all_private_chats'],
                'language_code' => 'it',
            ],
        ];
    }

    public function testTheMenusOfThePrivateChatsAreSetInEnglishAndItalian(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--platform' => 'telegram']));

        $this->assertSame([
            ['method' => 'setMyCommands', 'params' => self::menus()[0]],
            ['method' => 'setMyCommands', 'params' => self::menus()[1]],
        ], $this->telegram->botCalls());
        $this->assertSame([], $this->discord->requests);
        $this->assertStringContainsString('2 command(s) registered on Telegram for the private chats (menus: default, it)', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testDryRunPrintsBothMenusWithoutCallingTelegramEvenWithoutBotToken(): void
    {
        $tester = $this->tester(dsn: '');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--platform' => 'telegram', '--dry-run' => true]));

        $this->assertSame([], $this->telegram->requests);
        // One JSON document, printed raw: the console formatter would take "<pong>" for a tag
        $this->assertSame(self::menus(), json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testWithoutBotTokenNothingIsSyncedAndTheDeployGoesOn(): void
    {
        $tester = $this->tester(dsn: '');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--platform' => 'telegram']));

        $this->assertStringContainsString('TELEGRAM_DSN has no bot token', $tester->getDisplay());
        $this->assertSame([], $this->telegram->requests);
        $this->assertSame([], $this->discord->requests);
    }

    public function testARefusalFailsWithTelegramsErrorAndWithoutTheToken(): void
    {
        $this->telegram->botAnswers['setMyCommands'] = [[400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BOT_COMMAND_INVALID']]];
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--platform' => 'telegram']));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('BOT_COMMAND_INVALID', $display);
        $this->assertStringContainsString('left as they were', $display);
        $this->assertStringNotContainsString('bot-secret', $display);
        $this->assertSame(['setMyCommands'], array_column($this->telegram->botCalls(), 'method'));
    }

    public function testARefusedItalianMenuSaysWhatWasReplaced(): void
    {
        $this->telegram->botAnswers['setMyCommands'] = [[200, ['ok' => true, 'result' => true]], [400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: language code is invalid']]];
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--platform' => 'telegram']));

        $this->assertStringContainsString('Menus replaced before the failure: default.', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $this->assertSame(['setMyCommands', 'setMyCommands'], array_column($this->telegram->botCalls(), 'method'));
    }

    public function testDescriptionsAreCutToTheLimitOfTelegram(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['Long' => str_repeat('é', 300)], 'it', DiscordCommandPayloadBuilder::TRANSLATION_DOMAIN);
        $definition = new ChatCommandBuilder('long', 'Long')->getDefinition();

        $menus = new TelegramCommandPayloadBuilder($translator)->build([$definition]);

        $this->assertSame('Long', $menus[0]['commands'][0]['description']);
        $this->assertSame(TelegramCommandPayloadBuilder::MAX_DESCRIPTION, mb_strlen($menus[1]['commands'][0]['description']));
        $this->assertStringEndsWith('…', $menus[1]['commands'][0]['description']);
    }
}
