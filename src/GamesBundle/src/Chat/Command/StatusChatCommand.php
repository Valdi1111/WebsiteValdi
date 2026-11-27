<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatUser;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;

/**
 * /status: the compact view of each profile, one line per section of the summary (the notes sections, which share one
 * read, and the check-in). The endgames cost one call per mode and have their own command.
 *
 * The profiles of games without any of these sections are left out; an unreadable profile is kept, its embed says why.
 */
#[AsChatCommand(name: 'status', description: 'Show the status of your game profiles', role: AbstractProfileChatCommand::ROLE)]
class StatusChatCommand extends AbstractProfileChatCommand
{
    public function __construct(
        private readonly GameProfileStatusCollector $collector,
        private readonly ProfileSummaryPresenter $summaryPresenter,
    ) {
    }

    protected function getSectionKeys(): array
    {
        return $this->collector->getSummarySectionKeys();
    }

    protected function getSubject(): string
    {
        return 'anything to show';
    }

    protected function buildEmbeds(ChatUser $user, ChatProfileSelection $selection, bool $fresh): array
    {
        $embeds = [];
        foreach ($this->collector->collect($selection->profiles, $this->getSectionKeys(), $fresh) as $status) {
            if ($status->sections !== [] || $status->error !== null) {
                $embeds[] = $this->summaryPresenter->present($status);
            }
        }

        return $embeds;
    }
}
