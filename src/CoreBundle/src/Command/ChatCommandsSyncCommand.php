<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\DiscordBotApiException;
use App\CoreBundle\Exception\InvalidChatCommandException;
use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\Discord\DiscordCommandPayloadBuilder;
use App\CoreBundle\Service\Chat\Telegram\TelegramCommandPayloadBuilder;
use App\CoreBundle\Service\Discord\DiscordBotApiClient;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers the chat commands (#[AsChatCommand]) on a platform, replacing what was there, so a command removed from the
 * code disappears from the platform too:
 * - Discord: a bulk overwrite of the global commands of the application;
 * - Telegram: the command menu of the bot in the private chats (setMyCommands), in English and in each translated
 *   locale.
 * Run by every deploy, so it does nothing, successfully, while the bot is not configured.
 *
 * --dry-run prints the payload and calls nothing, so it works without the bot token too.
 */
#[AsCommand(name: 'chat:commands:sync', description: 'Register the chat commands on the platform, REPLACING every global command of the Discord application (or the private chat menu of the Telegram bot)')]
class ChatCommandsSyncCommand extends Command
{
    public function __construct(
        private readonly ChatCommandRegistry $registry,
        private readonly DiscordCommandPayloadBuilder $payloadBuilder,
        private readonly DiscordBotApiClient $botApi,
        private readonly TelegramCommandPayloadBuilder $telegramPayloadBuilder,
        private readonly TelegramBotApiClient $telegramBotApi,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Platform to register the commands on (discord or telegram)')]
        string $platform = 'discord',
        #[Option('Print the payload without calling the platform', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $chatPlatform = ChatPlatform::tryFrom($platform);
        if ($chatPlatform !== ChatPlatform::Discord && $chatPlatform !== ChatPlatform::Telegram) {
            $io->error(sprintf('Unknown platform "%s": use "discord" or "telegram".', $platform));

            return Command::INVALID;
        }

        $configured = $chatPlatform === ChatPlatform::Telegram ? $this->telegramBotApi->isConfigured() : $this->botApi->isConfigured();
        if (!$dryRun && !$configured) {
            $io->note($chatPlatform === ChatPlatform::Telegram
                ? 'TELEGRAM_DSN has no bot token: the Telegram bot is disabled, no command was registered.'
                : 'DISCORD_BOT_TOKEN is empty: the Discord bot is disabled, no command was registered.');

            return Command::SUCCESS;
        }

        try {
            $definitions = $this->registry->getDefinitions();
            $payload = $chatPlatform === ChatPlatform::Telegram
                ? $this->telegramPayloadBuilder->build($definitions)
                : $this->payloadBuilder->build($definitions);
        } catch (InvalidChatCommandException $e) {
            $io->error($e->getMessage().' Nothing was registered.');

            return Command::FAILURE;
        }

        if ($dryRun) {
            // Raw: the console formatter must not read "<...>" in descriptions as tags
            $output->writeln(json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        return $chatPlatform === ChatPlatform::Telegram
            ? $this->syncTelegram($io, $payload, count($definitions))
            : $this->syncDiscord($io, $payload);
    }

    /**
     * @param list<array<string, mixed>> $commands
     */
    private function syncDiscord(SymfonyStyle $io, array $commands): int
    {
        try {
            $applicationId = $this->botApi->getApplicationId();
            $count = $this->botApi->putGlobalCommands($applicationId, $commands);
        } catch (DiscordBotApiException $e) {
            $io->error($e->getMessage().' The commands on Discord were left as they were.');

            return Command::FAILURE;
        }

        $io->success(sprintf('%d command(s) registered on Discord (application %s), replacing the previous ones.', $count, $applicationId));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $menus setMyCommands parameters: the default menu, then one per locale
     */
    private function syncTelegram(SymfonyStyle $io, array $menus, int $count): int
    {
        $locales = [];
        foreach ($menus as $menu) {
            try {
                $this->telegramBotApi->call('setMyCommands', $menu);
            } catch (TelegramBotApiException $e) {
                // The menus before this one were replaced already: a new run replaces them all again
                $io->error($e->getMessage().($locales === [] ? ' The commands on Telegram were left as they were.' : sprintf(' Menus replaced before the failure: %s. Run the command again.', implode(', ', $locales))));

                return Command::FAILURE;
            }
            $locales[] = $menu['language_code'] ?? 'default';
        }

        $io->success(sprintf('%d command(s) registered on Telegram for the private chats (menus: %s), replacing the previous ones.', $count, implode(', ', $locales)));

        return Command::SUCCESS;
    }
}
