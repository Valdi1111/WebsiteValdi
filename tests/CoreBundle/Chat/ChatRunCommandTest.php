<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Command\ChatRunCommand;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatCommandLineParser;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Chat\Discord\DiscordReplyRenderer;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\Chat\TextReplyRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ChatRunCommandTest extends TestCase
{
    private ChatTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ChatTestKit();
        $this->kit->user(1, ['ROLE_GAMES']);
        $this->kit->user(2);
        $this->kit->user(3, disabled: true);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ChatRunCommand(
            $this->kit->users,
            new ChatCommandLineParser($this->kit->commands()),
            $this->kit->dispatcher(),
            new TextReplyRenderer(new ChatActionCodec()),
            new DiscordReplyRenderer(new DiscordEmbedTransformer(), new DiscordEmbedLimiter(), new ChatActionCodec()),
        ));
    }

    public function testHelpListsTheCommandsOfTheUser(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => 'User2@Example.test', 'line' => '/help']));
        $this->assertSame(<<<'TEXT'
            Commands
            /echo · Repeat what you type
            /help · List the commands you can use

            TEXT, $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', 'line' => 'help']));
        $output = $tester->getDisplay();
        $this->assertStringContainsString("/remind stamina · Remind me when the stamina reaches a value\n/remind list · List your reminders", $output);
        $this->assertStringNotContainsString('/admin', $output);
        $this->assertStringNotContainsString('/remind ·', $output);
    }

    public function testHelpInTheLanguageOfTheUser(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '2', 'line' => '/help', '--locale' => 'it']));
        $this->assertStringContainsString('/help · Elenca i comandi che puoi usare', $tester->getDisplay());
        // Not translated yet: the English text
        $this->assertStringContainsString('/echo · Repeat what you type', $tester->getDisplay());
    }

    public function testCommandRunsInProcessWithTypedArguments(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', 'line' => '/remind stamina value=120']));
        $this->assertSame("stamina {\"value\":120}\n", $tester->getDisplay());
        $this->assertSame(ChatPlatform::Cli, $this->kit->remind->requests[0]->platform);
        $this->assertSame('1', $this->kit->remind->requests[0]->user->platformUserId);
        $this->assertSame([], $this->kit->bus->messages);
    }

    public function testRefusalsFailWithTheAnswerOfTheBots(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '1', 'line' => '/nope']));
        $this->assertStringContainsString('Unknown command "/nope". Use /help', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '2', 'line' => '/remind list']));
        $this->assertStringContainsString('You cannot use this command.', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '1', 'line' => '/remind stamina lots']));
        $this->assertStringContainsString('The option "value" must be a whole number.', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['user' => '3', 'line' => '/help']));
        $this->assertStringContainsString('Your account has been disabled.', $tester->getDisplay());
    }

    public function testInvalidInput(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['user' => 'nobody@example.test', 'line' => '/help']));
        $this->assertStringContainsString('No user "nobody@example.test"', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '99', 'line' => '/help']));

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', 'line' => '/help me']));
        $this->assertStringContainsString('Too many arguments for /help', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['user' => '1', 'line' => '/help', '--render' => 'telegram']));
        $this->assertStringContainsString('Unknown renderer "telegram". Renderers: text, discord.', $tester->getDisplay());
    }

    public function testAnswerIsPrintedAsIs(): void
    {
        $this->kit->echo->reply = null;
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '1', 'line' => '/echo "<error>not a tag</error>"']));
        $this->assertSame("<error>not a tag</error>\n", $tester->getDisplay());
    }

    public function testDiscordRenderPrintsTheMessageDiscordWouldGet(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => '2', 'line' => '/help', '--render' => 'discord']));
        $message = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('', $message['content']);
        $this->assertSame('Commands', $message['embeds'][0]['title']);
        $this->assertStringContainsString('/help · List the commands you can use', $message['embeds'][0]['description']);
        $this->assertSame([], $message['components']);
        $this->assertSame(['parse' => []], $message['allowed_mentions']);
    }
}
