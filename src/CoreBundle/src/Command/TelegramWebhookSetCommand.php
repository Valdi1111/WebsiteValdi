<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\RemoteEvent\TelegramRemoteEventConsumer;
use App\CoreBundle\Service\Chat\Telegram\TelegramUpdateHandler;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Points the bot of TELEGRAM_DSN to /webhook/telegram on DEFAULT_URI, with TELEGRAM_WEBHOOK_SECRET as the secret
 * Telegram sends back with every update (checked by TelegramRequestParser). Run by every deploy, so it does nothing,
 * successfully, while the bot or the secret is not configured.
 *
 * setWebhook replaces the previous webhook, and Telegram keeps the pending updates: running it again is harmless.
 */
#[AsCommand(name: 'telegram:webhook:set', description: 'Send the Telegram bot updates to /webhook/telegram of DEFAULT_URI, with the secret of TELEGRAM_WEBHOOK_SECRET')]
class TelegramWebhookSetCommand extends Command
{
    /** Route of symfony/webhook (/webhook/{type}) */
    public const string ROUTE = '_webhook_controller';
    /** What Telegram accepts as secret_token */
    public const string SECRET_PATTERN = '/^[A-Za-z0-9_-]{1,256}$/';

    public function __construct(
        private readonly TelegramBotApiClient $botApi,
        private readonly UrlGeneratorInterface $urlGenerator,
        // CoreBundle routes have no host: the webhook is on DEFAULT_URI, as the OAuth callbacks
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        #[Autowire(env: 'TELEGRAM_WEBHOOK_SECRET')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Print what would be sent to Telegram, without the secret, and call nothing', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $parameters = [
            'url' => rtrim($this->defaultUri, '/').$this->urlGenerator->generate(self::ROUTE, ['type' => TelegramRemoteEventConsumer::TYPE]),
            'allowed_updates' => TelegramUpdateHandler::ALLOWED_UPDATES,
        ];

        if ($dryRun) {
            $output->writeln(json_encode($parameters + [
                'secret_token' => $this->secret !== '' ? sprintf('(TELEGRAM_WEBHOOK_SECRET, %d characters)', strlen($this->secret)) : '(empty: the webhook would not be set)',
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (!$this->botApi->isConfigured() || $this->secret === '') {
            $io->note('TELEGRAM_DSN has no bot token or TELEGRAM_WEBHOOK_SECRET is empty: the Telegram webhook is not in use, nothing was set.');

            return Command::SUCCESS;
        }
        // Checked here: Telegram's refusal would not say which characters are wrong
        if (preg_match(self::SECRET_PATTERN, $this->secret) !== 1) {
            $io->error('TELEGRAM_WEBHOOK_SECRET must be 1 to 256 characters among A-Z a-z 0-9 _ - (e.g. "openssl rand -hex 32"). Nothing was set.');

            return Command::FAILURE;
        }

        try {
            $this->botApi->call('setWebhook', $parameters + ['secret_token' => $this->secret]);
        } catch (TelegramBotApiException $e) {
            $io->error($e->getMessage().' The webhook was left as it was.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Telegram webhook set to %s.', $parameters['url']));

        return Command::SUCCESS;
    }
}
