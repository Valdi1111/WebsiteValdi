<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Exception\DiscordBotApiException;
use App\CoreBundle\Exception\InvalidChatCommandException;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\Discord\DiscordCommandPayloadBuilder;
use App\CoreBundle\Service\Discord\DiscordBotApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers the chat commands (#[AsChatCommand]) on a platform, today Discord: a bulk overwrite of the global commands
 * of the application, so a command removed from the code disappears from Discord too. Run by every deploy, so it does
 * nothing, successfully, while the bot is not configured.
 *
 * --dry-run prints the payload and calls nothing, so it works without the bot token too.
 */
#[AsCommand(name: 'chat:commands:sync', description: 'Register the chat commands on the platform, REPLACING every global command of the Discord application')]
class ChatCommandsSyncCommand extends Command
{
    public function __construct(
        private readonly ChatCommandRegistry $registry,
        private readonly DiscordCommandPayloadBuilder $payloadBuilder,
        private readonly DiscordBotApiClient $botApi,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Platform to register the commands on (discord; telegram is not implemented yet)')]
        string $platform = 'discord',
        #[Option('Print the payload without calling the platform', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        if (ChatPlatform::tryFrom($platform) === ChatPlatform::Telegram) {
            $io->error('The Telegram commands are not implemented yet: nothing was registered.');

            return Command::FAILURE;
        }
        if (ChatPlatform::tryFrom($platform) !== ChatPlatform::Discord) {
            $io->error(sprintf('Unknown platform "%s": use "discord".', $platform));

            return Command::INVALID;
        }

        if (!$dryRun && !$this->botApi->isConfigured()) {
            $io->note('DISCORD_BOT_TOKEN is empty: the Discord bot is disabled, no command was registered.');

            return Command::SUCCESS;
        }

        try {
            $commands = $this->payloadBuilder->build($this->registry->getDefinitions());
        } catch (InvalidChatCommandException $e) {
            $io->error($e->getMessage().' Nothing was registered.');

            return Command::FAILURE;
        }

        if ($dryRun) {
            // Raw: the console formatter must not read "<...>" in descriptions as tags
            $output->writeln(json_encode($commands, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

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
}
