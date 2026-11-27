<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;

/**
 * /weeklies: every weekly activity of each profile, done or not.
 */
#[AsChatCommand(name: 'weeklies', description: 'Show the weekly activities of your game profiles', role: AbstractProfileChatCommand::ROLE)]
class WeekliesChatCommand extends AbstractSectionChatCommand
{
    protected function getSectionKey(): string
    {
        return 'weeklies';
    }

    protected function getSubject(): string
    {
        return 'weeklies';
    }
}
