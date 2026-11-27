<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatUser;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Presenter\ProfileOverviewPresenter;

/**
 * /profiles: every selected profile with its state, the login of its account, the reminders and automations turned on,
 * the endgame targets and the notification platforms. Database only, so the answer has no Refresh button.
 */
#[AsChatCommand(name: 'profiles', description: 'Show your game profiles and their reminders', role: AbstractProfileChatCommand::ROLE)]
class ProfilesChatCommand extends AbstractProfileChatCommand
{
    public function __construct(
        private readonly ProfileOverviewPresenter $presenter,
    ) {
    }

    public function canRefresh(): bool
    {
        return false;
    }

    protected function getSectionKeys(): array
    {
        return [];
    }

    protected function getSubject(): string
    {
        return 'a profile';
    }

    protected function buildEmbeds(ChatUser $user, ChatProfileSelection $selection, bool $fresh): array
    {
        return array_map($this->presenter->present(...), $selection->profiles);
    }
}
