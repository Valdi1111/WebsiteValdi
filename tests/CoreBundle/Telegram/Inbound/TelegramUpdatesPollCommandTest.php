<?php

namespace App\Tests\CoreBundle\Telegram\Inbound;

use App\CoreBundle\Command\TelegramUpdatesPollCommand;
use App\CoreBundle\Service\Chat\Telegram\TelegramUpdateHandler;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * telegram:updates:poll against FakeTelegramApi, with the updates given to a recording TelegramUpdateHandler (tested
 * on its own). Each getUpdates moves the MockClock by its long-poll timeout, as the real wait would.
 */
class TelegramUpdatesPollCommandTest extends TestCase
{
    private MockClock $clock;
    private FakeTelegramApi $telegram;
    /** @var list<array{array<string, mixed>, bool}> the updates handled, and whether as polled */
    private array $handled = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-27 21:00:00');
        $this->telegram = new FakeTelegramApi($this->clock);
    }

    private function tester(string $dsn = 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default'): CommandTester
    {
        $api = new class($this->telegram->client(FakeTelegramApi::BOT_API), $dsn, $this->clock) extends TelegramBotApiClient {
            public function __construct(HttpClientInterface $client, string $botDsn, private readonly MockClock $clock)
            {
                parent::__construct($client, $botDsn);
            }

            public function call(string $method, array $parameters = [], ?float $timeout = null): mixed
            {
                if ($method === 'getUpdates') {
                    $this->clock->sleep($parameters['timeout'] ?? 0);
                }

                return parent::call($method, $parameters, $timeout);
            }
        };
        $handled = &$this->handled;
        $handler = new class($handled) extends TelegramUpdateHandler {
            /** @noinspection PhpMissingParentConstructorInspection only handle() is used */
            public function __construct(private array &$handled)
            {
            }

            public function handle(array $update, bool $polled = false): void
            {
                $this->handled[] = [$update, $polled];
            }
        };

        return new CommandTester(new TelegramUpdatesPollCommand($api, $handler, $this->clock));
    }

    /**
     * @return array<string, mixed>
     */
    private function update(int $id, string $text = '/help'): array
    {
        return ['update_id' => $id, 'message' => [
            'message_id' => $id,
            'from' => ['id' => (int) FakeTelegramApi::USER_ID, 'is_bot' => false, 'first_name' => 'Nelly'],
            'chat' => ['id' => (int) FakeTelegramApi::USER_ID, 'type' => 'private'],
            'date' => $this->clock->now()->getTimestamp(),
            'text' => $text,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @return array{int, array<string, mixed>}
     */
    private static function updates(array $updates): array
    {
        return [200, ['ok' => true, 'result' => $updates]];
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testOncePollsOnceHandlesTheUpdatesAsPolledAndConfirmsThem(): void
    {
        $updates = [$this->update(700000010), $this->update(700000011, 'hello')];
        $this->telegram->updates = [$updates, []];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));

        $this->assertSame([[$updates[0], true], [$updates[1], true]], $this->handled);
        $this->assertSame([
            ['method' => 'getWebhookInfo', 'params' => []],
            ['method' => 'getUpdates', 'params' => ['timeout' => 30, 'allowed_updates' => ['message', 'callback_query']]],
            // Without it the next run would get them again
            ['method' => 'getUpdates', 'params' => ['offset' => 700000012, 'timeout' => 0, 'limit' => 1]],
        ], $this->telegram->botCalls());
        // The HTTP timeout is above the long poll
        $this->assertSame(40.0, (float) $this->telegram->requests[1]['options']['timeout']);
        $this->assertStringContainsString('Update 700000010: message', self::display($tester));
        $this->assertStringContainsString('2 update(s) handled.', self::display($tester));
    }

    public function testOnceWithoutUpdatesHasNothingToConfirm(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true, '--timeout' => '0']));

        $this->assertSame([], $this->handled);
        $this->assertSame([
            ['method' => 'getWebhookInfo', 'params' => []],
            ['method' => 'getUpdates', 'params' => ['timeout' => 0, 'allowed_updates' => ['message', 'callback_query']]],
        ], $this->telegram->botCalls());
        $this->assertStringContainsString('0 update(s) handled.', self::display($tester));
    }

    public function testEachPollAsksForTheUpdatesAfterTheLastOneHandled(): void
    {
        // Updates that are not objects are skipped; a 409 stops the loop
        $this->telegram->botAnswers['getUpdates'] = [
            self::updates([$this->update(700000010), $this->update(700000011)]),
            self::updates([['update_id' => 'x'], $this->update(700000012)]),
            self::updates([]),
            [409, ['ok' => false, 'error_code' => 409, 'description' => "Conflict: can't use getUpdates method while webhook is active; use deleteWebhook to delete the webhook first"]],
        ];
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $this->assertSame([700000010, 700000011, 700000012], array_map(static fn (array $handled): int => $handled[0]['update_id'], $this->handled));
        $this->assertSame([null, 700000012, 700000013, 700000013], array_map(
            static fn (array $call): ?int => $call['params']['offset'] ?? null,
            array_values(array_filter($this->telegram->botCalls(), static fn (array $call): bool => $call['method'] === 'getUpdates')),
        ));
        $display = self::display($tester);
        $this->assertStringContainsString("Conflict: can't use getUpdates method while webhook is active", $display);
        $this->assertStringContainsString('a webhook was set meanwhile', $display);
        $this->assertStringNotContainsString('bot-secret', $tester->getDisplay());
    }

    public function testTheNextRunStartsAfterTheUpdatesTheLastOneConfirmed(): void
    {
        $this->telegram->updates = [[$this->update(700000010)], [], [$this->update(700000011)], []];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));

        $this->assertSame([700000010, 700000011], array_map(static fn (array $handled): int => $handled[0]['update_id'], $this->handled));
        // Each run confirms its updates: Telegram then starts the next one after them, without an offset kept here
        $this->assertSame([null, 700000011, null, 700000012], array_map(
            static fn (array $call): ?int => $call['params']['offset'] ?? null,
            array_values(array_filter($this->telegram->botCalls(), static fn (array $call): bool => $call['method'] === 'getUpdates')),
        ));
    }

    public function testTheTimeLimitShortensTheLastPoll(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--time-limit' => '70']));

        $this->assertSame([30, 30, 10], array_map(
            static fn (array $call): int => $call['params']['timeout'],
            array_values(array_filter($this->telegram->botCalls(), static fn (array $call): bool => $call['method'] === 'getUpdates')),
        ));
    }

    public function testItRefusesToPollWhileTheBotHasAWebhook(): void
    {
        $this->telegram->webhookUrl = 'https://valdi.test/webhook/telegram';
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--once' => true]));

        $display = self::display($tester);
        $this->assertStringContainsString('The bot has a webhook (https://valdi.test/webhook/telegram)', $display);
        $this->assertStringContainsString('telegram:webhook:delete', $display);
        // The webhook is left alone
        $this->assertSame(['getWebhookInfo'], array_column($this->telegram->botCalls(), 'method'));
        $this->assertSame([], $this->handled);
    }

    public function testAFailureOfASinglePollStops(): void
    {
        $this->telegram->botAnswers['getUpdates'] = [[502, ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']]];
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        $this->assertStringContainsString('Telegram refused getUpdates: HTTP 502, Bad Gateway.', self::display($tester));
        $this->assertStringNotContainsString('bot-secret', $tester->getDisplay());
    }

    public function testItNeedsABotAndValidDurations(): void
    {
        $tester = $this->tester('');
        $this->assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        $this->assertStringContainsString('TELEGRAM_DSN has no bot token.', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(Command::INVALID, $tester->execute(['--timeout' => '-1']));
        $this->assertSame([], $this->telegram->requests);
    }

    public function testCtrlCStopsAtOnceWhileWaitingAndAfterTheUpdateOtherwise(): void
    {
        $command = new TelegramUpdatesPollCommand($this->createStub(TelegramBotApiClient::class), $this->createStub(TelegramUpdateHandler::class), $this->clock);
        // Without pcntl there are no signals to subscribe to
        $this->assertSame(\defined('SIGINT') ? [\SIGINT, \SIGTERM] : [], $command->getSubscribedSignals());

        // Not waiting on Telegram: the loop stops after the current update (2 = SIGINT, 15 = SIGTERM)
        $this->assertFalse($command->handleSignal(2));
        (new \ReflectionProperty($command, 'waiting'))->setValue($command, true);
        $this->assertSame(Command::SUCCESS, $command->handleSignal(15));
    }
}
