<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;

/**
 * A command with subcommands, for the users with ROLE_GAMES.
 */
#[AsChatCommand(name: 'remind', description: 'Manage your reminders', role: 'ROLE_GAMES')]
class RemindChatCommand implements ChatCommandInterface
{
    /** @var list<ChatRequest> */
    public array $requests = [];

    public function configure(ChatCommandBuilder $builder): void
    {
        $builder
            ->addSubcommand('stamina', 'Remind me when the stamina reaches a value', static function (ChatCommandBuilder $stamina): void {
                $stamina
                    ->addOption('value', 'Stamina to reach', ChatOptionType::Integer, required: true)
                    ->addOption('game', 'Only this game', autocomplete: true);
            })
            ->addSubcommand('list', 'List your reminders');
    }

    public function execute(ChatRequest $request): ChatReply
    {
        $this->requests[] = $request;

        return ChatReply::text(sprintf('%s %s', $request->subcommand, json_encode($request->arguments)));
    }
}
