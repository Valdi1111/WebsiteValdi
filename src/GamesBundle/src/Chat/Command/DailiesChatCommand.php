<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;

/**
 * /dailies: every daily activity of each profile, done or not.
 */
#[AsChatCommand(name: 'dailies', description: 'Show the daily activities of your game profiles', role: AbstractProfileChatCommand::ROLE)]
class DailiesChatCommand extends AbstractSectionChatCommand
{
    protected function getSectionKey(): string
    {
        return 'dailies';
    }

    protected function getSubject(): string
    {
        return 'dailies';
    }
}
