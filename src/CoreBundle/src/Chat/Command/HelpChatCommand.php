<?php

namespace App\CoreBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * /help: the commands the user may run, with their description in the user's language (domain "chat"). A command with
 * subcommands is listed by subcommand, as the platforms run it.
 */
#[AsChatCommand(name: 'help', description: 'List the commands you can use')]
class HelpChatCommand implements ChatCommandInterface
{
    public function __construct(
        private readonly ChatCommandRegistry $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function configure(ChatCommandBuilder $builder): void
    {
    }

    public function execute(ChatRequest $request): ChatReply
    {
        $lines = [];
        foreach ($this->commands->getDefinitionsFor($request->user->user) as $definition) {
            if ($definition->subcommands === []) {
                $lines[] = sprintf('/%s · %s', $definition->name, $this->translate($definition->description, $request->locale));
            }
            foreach ($definition->subcommands as $subcommand) {
                $lines[] = sprintf('/%s %s · %s', $definition->name, $subcommand->name, $this->translate($subcommand->description, $request->locale));
            }
        }

        return ChatReply::embeds([new UniversalEmbed()->setTitle('Commands')->setDescription(implode("\n", $lines))]);
    }

    private function translate(string $description, string $locale): string
    {
        return $this->translator->trans($description, [], 'chat', $locale);
    }
}
