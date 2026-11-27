<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes the webhook of the bot of TELEGRAM_DSN, so that it can be polled (telegram:updates:poll). The next deploy
 * sets it again (telegram:webhook:set) where TELEGRAM_WEBHOOK_SECRET is set.
 */
#[AsCommand(name: 'telegram:webhook:delete', description: 'Remove the webhook of the Telegram bot (to poll its updates instead)')]
class TelegramWebhookDeleteCommand extends Command
{
    public function __construct(
        private readonly TelegramBotApiClient $botApi,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Also drop the updates Telegram is still holding for the bot', name: 'drop-pending')]
        bool $dropPending = false,
    ): int {
        if (!$this->botApi->isConfigured()) {
            $io->error('TELEGRAM_DSN has no bot token.');

            return Command::FAILURE;
        }

        try {
            $this->botApi->call('deleteWebhook', ['drop_pending_updates' => $dropPending]);
        } catch (TelegramBotApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($dropPending ? 'Telegram webhook deleted, pending updates dropped.' : 'Telegram webhook deleted: the pending updates can be polled.');

        return Command::SUCCESS;
    }
}
