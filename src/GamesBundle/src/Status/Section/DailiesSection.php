<?php

namespace App\GamesBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Status\AbstractNotesSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesDailies>
 */
#[AsTaggedItem(priority: 90)]
class DailiesSection extends AbstractNotesSection
{
    public function __construct(
        private readonly DailiesPresenter $presenter,
    ) {
    }

    public function getKey(): string
    {
        return 'dailies';
    }

    public function getLabel(): string
    {
        return 'Dailies';
    }

    public function getIcon(): string
    {
        return '📅';
    }

    protected function getCapability(): string
    {
        return HasDailiesInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesDailies
    {
        /** @var HasDailiesInterface $service */
        $service = $context->gameService;

        return $service->getDailiesData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Dailies');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        return $this->summarizeMetrics($data);
    }
}
