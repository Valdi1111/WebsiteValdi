<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Service\Chat\Telegram\TelegramUpdateHandler;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The updates of the bot of TELEGRAM_DSN by long polling (getUpdates), handled in this process: for development,
 * where Telegram cannot reach the site. Telegram refuses getUpdates while a webhook is set, so the command stops
 * then, without touching the webhook.
 *
 * Telegram forgets an update once getUpdates is called with a higher offset: every call confirms the updates handled
 * before it, and the last ones are confirmed before stopping. Updates older than 5 minutes are skipped
 * (TelegramUpdateHandler::handle($update, polled: true)), so starting the poller does not replay a backlog. Ctrl+C
 * stops at once while waiting for Telegram, after the update being handled otherwise.
 */
#[AsCommand(name: 'telegram:updates:poll', description: 'Handle the Telegram bot updates by polling them (development, without a webhook)')]
class TelegramUpdatesPollCommand extends Command
{
    /** Seconds to wait after a temporary failure (Telegram unreachable, 429, 5xx) before polling again */
    public const int RETRY_DELAY_SECONDS = 5;
    /** Above the long poll: Telegram answers at its end at the latest */
    public const int HTTP_TIMEOUT_MARGIN_SECONDS = 10;

    /** Inside getUpdates: everything handled so far is confirmed by that call, a signal can stop at once */
    private bool $waiting = false;
    private bool $stopRequested = false;

    public function __construct(
        private readonly TelegramBotApiClient $botApi,
        private readonly TelegramUpdateHandler $handler,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Make a single getUpdates call, handle what it gives and stop')]
        bool $once = false,
        #[Option('Seconds Telegram keeps a getUpdates call open while there is no update')]
        int $timeout = 30,
        #[Option('Stop after this many seconds (0: until Ctrl+C)', name: 'time-limit')]
        int $timeLimit = 0,
    ): int {
        if ($timeout < 0 || $timeLimit < 0) {
            $io->error('--timeout and --time-limit cannot be negative.');

            return Command::INVALID;
        }
        if (!$this->botApi->isConfigured()) {
            $io->error('TELEGRAM_DSN has no bot token.');

            return Command::FAILURE;
        }

        try {
            $webhook = $this->botApi->call('getWebhookInfo');
        } catch (TelegramBotApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $webhookUrl = is_array($webhook) && is_string($webhook['url'] ?? null) ? $webhook['url'] : '';
        if ($webhookUrl !== '') {
            $io->error(sprintf('The bot has a webhook (%s): Telegram sends the updates there and refuses getUpdates. To poll, remove it first with telegram:webhook:delete (the next deploy, or telegram:webhook:set, sets it again).', $webhookUrl));

            return Command::FAILURE;
        }

        $this->stopRequested = false;
        $deadline = $timeLimit > 0 ? $this->clock->now()->getTimestamp() + $timeLimit : null;
        $offset = null;
        $unconfirmed = false;
        $handled = 0;
        if (!$once) {
            $io->text('Polling the Telegram updates; Ctrl+C to stop.');
        }

        while (true) {
            $wait = $deadline !== null ? max(0, min($timeout, $deadline - $this->clock->now()->getTimestamp())) : $timeout;
            $parameters = ['timeout' => $wait, 'allowed_updates' => TelegramUpdateHandler::ALLOWED_UPDATES];
            if ($offset !== null) {
                $parameters['offset'] = $offset;
            }

            try {
                $this->waiting = true;
                $updates = $this->botApi->call('getUpdates', $parameters, $wait + self::HTTP_TIMEOUT_MARGIN_SECONDS);
                $unconfirmed = false;
            } catch (TelegramBotApiException $e) {
                if ($e->statusCode === 409) {
                    $io->error($e->getMessage().' Telegram gives the updates one way at a time: a webhook was set meanwhile (see telegram:webhook:info), or another poller of the same bot is running.');

                    return Command::FAILURE;
                }
                if ($once || !self::isTemporary($e)) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
                $io->warning(sprintf('%s Polling again in %d seconds.', $e->getMessage(), self::RETRY_DELAY_SECONDS));
                $updates = [];
                $this->waiting = false;
                // A signal interrupts the sleep
                sleep(self::RETRY_DELAY_SECONDS);
            } finally {
                $this->waiting = false;
            }

            foreach (is_array($updates) ? $updates : [] as $update) {
                if (!is_array($update) || !is_int($update['update_id'] ?? null)) {
                    continue;
                }
                $io->writeln(OutputFormatter::escape(sprintf('Update %d: %s', $update['update_id'], implode(', ', array_diff(array_keys($update), ['update_id'])))));
                $this->handler->handle($update, polled: true);
                $offset = $update['update_id'] + 1;
                $unconfirmed = true;
                ++$handled;
            }

            if ($once || $this->stopRequested || ($deadline !== null && $this->clock->now()->getTimestamp() >= $deadline)) {
                break;
            }
        }

        if ($unconfirmed) {
            $this->confirm($io, (int) $offset);
        }
        $io->success(sprintf('%d update(s) handled.', $handled));

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->waiting) {
            return Command::SUCCESS;
        }
        // In the middle of an update: it is finished and confirmed before stopping
        $this->stopRequested = true;

        return false;
    }

    /**
     * Confirms the updates handled last, which only a later getUpdates does: without it the next run would get them
     * again. Its own updates, if any, stay with Telegram (they are confirmed only by an offset above theirs).
     */
    private function confirm(SymfonyStyle $io, int $offset): void
    {
        try {
            $this->botApi->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 1]);
        } catch (TelegramBotApiException $e) {
            $io->warning($e->getMessage().' The last updates may be handled again by the next run.');
        }
    }

    private static function isTemporary(TelegramBotApiException $e): bool
    {
        return $e->statusCode === null || $e->statusCode === 429 || $e->statusCode >= 500;
    }
}
