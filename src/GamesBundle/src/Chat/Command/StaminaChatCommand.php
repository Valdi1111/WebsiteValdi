<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;

/**
 * /stamina: current and max stamina of each profile, and when it is full.
 */
#[AsChatCommand(name: 'stamina', description: 'Show the stamina of your game profiles', role: AbstractProfileChatCommand::ROLE)]
class StaminaChatCommand extends AbstractSectionChatCommand
{
    protected function getSectionKey(): string
    {
        return 'stamina';
    }

    protected function getSubject(): string
    {
        return 'stamina';
    }
}
