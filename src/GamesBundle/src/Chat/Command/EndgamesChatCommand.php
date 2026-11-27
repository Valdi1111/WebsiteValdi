<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;

/**
 * /endgames: every endgame mode of each profile, with its progress against the profile's targets and the days left.
 * The full embeds are large: this is the command that usually needs more than one page.
 */
#[AsChatCommand(name: 'endgames', description: 'Show the endgame modes of your game profiles', role: AbstractProfileChatCommand::ROLE)]
class EndgamesChatCommand extends AbstractSectionChatCommand
{
    protected function getSectionKey(): string
    {
        return 'endgames';
    }

    protected function getSubject(): string
    {
        return 'endgames';
    }
}
