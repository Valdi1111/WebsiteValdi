<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The webhook of the bot of TELEGRAM_DSN as Telegram sees it (getWebhookInfo): URL, pending updates and the last
 * delivery error, e.g. a wrong secret or a site that does not answer. Telegram never gives the secret back.
 */
#[AsCommand(name: 'telegram:webhook:info', description: 'Show the webhook of the Telegram bot: URL, pending updates, last error')]
class TelegramWebhookInfoCommand extends Command
{
    public function __construct(
        private readonly TelegramBotApiClient $botApi,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->botApi->isConfigured()) {
            $io->error('TELEGRAM_DSN has no bot token.');

            return Command::FAILURE;
        }

        try {
            $info = $this->botApi->call('getWebhookInfo');
        } catch (TelegramBotApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $info = is_array($info) ? $info : [];

        $url = is_string($info['url'] ?? null) ? $info['url'] : '';
        $allowed = is_array($info['allowed_updates'] ?? null) ? implode(', ', array_filter($info['allowed_updates'], is_string(...))) : '';
        $lastError = is_int($info['last_error_date'] ?? null)
            ? sprintf('%s: %s', date('Y-m-d H:i:s T', $info['last_error_date']), is_string($info['last_error_message'] ?? null) ? $info['last_error_message'] : '?')
            : 'none';

        // Escaped: Telegram's texts are not console markup
        $io->definitionList(
            ['URL' => OutputFormatter::escape($url !== '' ? $url : 'none (no webhook: the updates can be polled)')],
            ['Pending updates' => (string) (is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 0)],
            ['Allowed updates' => OutputFormatter::escape($allowed !== '' ? $allowed : 'default')],
            ['Last error' => OutputFormatter::escape($lastError)],
        );

        return Command::SUCCESS;
    }
}
