<?php

namespace App\Tests\CoreBundle\Telegram\Inbound;

use App\CoreBundle\Command\TelegramWebhookDeleteCommand;
use App\CoreBundle\Command\TelegramWebhookInfoCommand;
use App\CoreBundle\Command\TelegramWebhookSetCommand;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * telegram:webhook:set, telegram:webhook:delete and telegram:webhook:info against FakeTelegramApi. The route is the
 * one of symfony/webhook, built by hand (TelegramInboundWiringTest checks the real one).
 */
class TelegramWebhookCommandsTest extends TestCase
{
    private const string DSN = 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default';
    private const string SECRET = 'test-webhook-secret';
    private const string DEFAULT_URI = 'https://valdi.test/';

    private FakeTelegramApi $telegram;

    protected function setUp(): void
    {
        $this->telegram = new FakeTelegramApi(new MockClock('2026-11-27 21:00:00'));
    }

    private function api(string $dsn = self::DSN): TelegramBotApiClient
    {
        return new TelegramBotApiClient($this->telegram->client(FakeTelegramApi::BOT_API), $dsn);
    }

    private function set(string $secret = self::SECRET, string $dsn = self::DSN): CommandTester
    {
        $routes = new RouteCollection();
        $routes->add(TelegramWebhookSetCommand::ROUTE, new Route('/webhook/{type}', requirements: ['type' => '.+']));

        return new CommandTester(new TelegramWebhookSetCommand($this->api($dsn), new UrlGenerator($routes, new RequestContext()), self::DEFAULT_URI, $secret));
    }

    private static function display(CommandTester $tester): string
    {
        // SymfonyStyle wraps the long lines
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testSetPointsTheBotToTheWebhookWithTheSecret(): void
    {
        $tester = $this->set();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([['method' => 'setWebhook', 'params' => [
            'url' => 'https://valdi.test/webhook/telegram',
            'allowed_updates' => ['message', 'callback_query'],
            'secret_token' => self::SECRET,
        ]]], $this->telegram->botCalls());
        $this->assertStringContainsString('Telegram webhook set to https://valdi.test/webhook/telegram.', self::display($tester));
        $this->assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testSetDoesNothingSuccessfullyWhileTheWebhookIsNotConfigured(): void
    {
        // As every deploy runs it: without a secret (development) or without a bot
        foreach ([$this->set(''), $this->set(dsn: '')] as $tester) {
            $this->assertSame(Command::SUCCESS, $tester->execute([]));
            $this->assertStringContainsString('nothing was set', self::display($tester));
        }
        $this->assertSame([], $this->telegram->requests);
    }

    public function testSetDryRunPrintsWhatWouldBeSentWithoutTheSecret(): void
    {
        $tester = $this->set(dsn: '');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $printed = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame([
            'url' => 'https://valdi.test/webhook/telegram',
            'allowed_updates' => ['message', 'callback_query'],
            'secret_token' => '(TELEGRAM_WEBHOOK_SECRET, 19 characters)',
        ], $printed);
        $this->assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        $this->assertSame([], $this->telegram->requests);

        $tester = $this->set('');
        $tester->execute(['--dry-run' => true]);
        $this->assertStringContainsString('(empty: the webhook would not be set)', $tester->getDisplay());
    }

    public function testSetRefusesASecretTelegramWouldRefuse(): void
    {
        $tester = $this->set('not a valid secret!');

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('A-Z a-z 0-9 _ -', self::display($tester));
        $this->assertStringNotContainsString('not a valid secret!', $tester->getDisplay());
        $this->assertSame([], $this->telegram->requests);
    }

    public function testSetFailsWithTheReasonOfTelegramNeverTheToken(): void
    {
        $this->telegram->botAnswers['setWebhook'] = [[400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: bad webhook: HTTPS url must be provided for webhook']]];
        $tester = $this->set();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('HTTPS url must be provided for webhook', self::display($tester));
        $this->assertStringContainsString('The webhook was left as it was.', self::display($tester));
        $this->assertStringNotContainsString('bot-secret', $tester->getDisplay());
        $this->assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testDeleteRemovesTheWebhookKeepingOrDroppingThePendingUpdates(): void
    {
        $tester = new CommandTester(new TelegramWebhookDeleteCommand($this->api()));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('the pending updates can be polled', self::display($tester));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--drop-pending' => true]));
        $this->assertStringContainsString('pending updates dropped', self::display($tester));

        $this->assertSame([
            ['method' => 'deleteWebhook', 'params' => ['drop_pending_updates' => false]],
            ['method' => 'deleteWebhook', 'params' => ['drop_pending_updates' => true]],
        ], $this->telegram->botCalls());
    }

    public function testDeleteAndInfoFailWithoutABotOrWhenTelegramRefuses(): void
    {
        foreach ([new TelegramWebhookDeleteCommand($this->api('')), new TelegramWebhookInfoCommand($this->api(''))] as $command) {
            $tester = new CommandTester($command);
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertStringContainsString('TELEGRAM_DSN has no bot token.', $tester->getDisplay());
        }
        $this->assertSame([], $this->telegram->requests);

        $this->telegram->botAnswers['deleteWebhook'] = [[401, ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']]];
        $tester = new CommandTester(new TelegramWebhookDeleteCommand($this->api()));
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Telegram refused deleteWebhook: HTTP 401, Unauthorized.', self::display($tester));
        $this->assertStringNotContainsString('bot-secret', $tester->getDisplay());
    }

    public function testInfoShowsTheWebhookAsTelegramSeesIt(): void
    {
        $this->telegram->botAnswers['getWebhookInfo'] = [[200, ['ok' => true, 'result' => [
            'url' => 'https://valdi.test/webhook/telegram',
            'has_custom_certificate' => false,
            'pending_update_count' => 3,
            'last_error_date' => 1795813200,
            'last_error_message' => 'Wrong response from the webhook: 403 <Forbidden>',
            'max_connections' => 40,
            'allowed_updates' => ['message', 'callback_query'],
        ]]]];
        $tester = new CommandTester(new TelegramWebhookInfoCommand($this->api()));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = self::display($tester);
        $this->assertStringContainsString('URL https://valdi.test/webhook/telegram', $display);
        $this->assertStringContainsString('Pending updates 3', $display);
        $this->assertStringContainsString('Allowed updates message, callback_query', $display);
        $this->assertStringContainsString(date('Y-m-d H:i:s T', 1795813200).': Wrong response from the webhook: 403 <Forbidden>', $display);
        $this->assertSame(['getWebhookInfo'], array_column($this->telegram->botCalls(), 'method'));
    }

    public function testInfoWithoutAWebhook(): void
    {
        $tester = new CommandTester(new TelegramWebhookInfoCommand($this->api()));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = self::display($tester);
        $this->assertStringContainsString('URL none (no webhook: the updates can be polled)', $display);
        $this->assertStringContainsString('Pending updates 0', $display);
        $this->assertStringContainsString('Last error none', $display);
    }
}
