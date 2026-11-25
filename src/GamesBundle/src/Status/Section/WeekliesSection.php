<?php

namespace App\GamesBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Status\AbstractNotesSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesWeeklies>
 */
#[AsTaggedItem(priority: 80)]
class WeekliesSection extends AbstractNotesSection
{
    public function __construct(
        private readonly WeekliesPresenter $presenter,
    ) {
    }

    public function getKey(): string
    {
        return 'weeklies';
    }

    public function getLabel(): string
    {
        return 'Weeklies';
    }

    public function getIcon(): string
    {
        return '🗓️';
    }

    protected function getCapability(): string
    {
        return HasWeekliesInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesWeeklies
    {
        /** @var HasWeekliesInterface $service */
        $service = $context->gameService;

        return $service->getWeekliesData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Weeklies');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        return $this->summarizeMetrics($data);
    }
}
