<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\InvalidChatCommandLineException;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\Chat\ChatCommandLineParser;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\Discord\DiscordReplyRenderer;
use App\CoreBundle\Service\Chat\TextReplyRenderer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A chat command run as a user, without a bot and without the queue: the same checks as Discord and Telegram (role,
 * options...), then the command in this process. For development (the dev host is not reachable from Discord) and
 * support. The exit code is 1 when the answer is a refusal or an error.
 */
#[AsCommand(name: 'chat:run', description: 'Run a chat command (bot command) as a user and print the answer')]
class ChatRunCommand extends Command
{
    /** How the answer can be printed */
    public const array RENDERERS = ['text', 'discord'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ChatCommandLineParser $parser,
        private readonly ChatDispatcher $dispatcher,
        private readonly TextReplyRenderer $textRenderer,
        private readonly DiscordReplyRenderer $discordRenderer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Id or email of the user')]
        string $user,
        #[Argument('The command as typed in a chat: "/help", "/remind stamina 120" (options in order, or name=value)')]
        string $line,
        #[Option('How to print the answer: text, or discord (the JSON message Discord would get)')]
        string $render = 'text',
        #[Option('Language of the user, as a platform would send it (translated command descriptions)')]
        string $locale = 'en',
    ): int {
        if (!in_array($render, self::RENDERERS, true)) {
            $io->error(sprintf('Unknown renderer "%s". Renderers: %s.', $render, implode(', ', self::RENDERERS)));

            return Command::INVALID;
        }

        $owner = ctype_digit($user)
            ? $this->userRepository->find((int) $user)
            : $this->userRepository->findOneBy(['email' => mb_strtolower(trim($user))]);
        if (!$owner instanceof User) {
            $io->error(sprintf('No user "%s".', $user));

            return Command::INVALID;
        }

        try {
            $command = $this->parser->parse($line);
        } catch (InvalidChatCommandLineException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        // As chat:run, the platform user id is the site user id
        $result = $this->dispatcher->runCommand(ChatPlatform::Cli, (string) $owner->getId(), $command->command, $command->subcommand, $command->arguments, $locale);
        $io->writeln(OutputFormatter::escape(match ($render) {
            'discord' => json_encode($this->discordRenderer->render($result->reply), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            default => $this->textRenderer->render($result->reply),
        }));

        return $result->failed ? Command::FAILURE : Command::SUCCESS;
    }
}
