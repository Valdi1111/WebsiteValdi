<?php

namespace App\HoyoverseBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Status\AbstractNotesSection;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Presenter\ExpeditionsPresenter;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesExpeditions>
 */
#[AsTaggedItem(priority: 70)]
class ExpeditionsSection extends AbstractNotesSection
{
    public function __construct(
        private readonly ExpeditionsPresenter $presenter,
        private readonly DurationFormatter $durationFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return 'expeditions';
    }

    public function getLabel(): string
    {
        return 'Expeditions';
    }

    public function getIcon(): string
    {
        return '🧭';
    }

    protected function getCapability(): string
    {
        return HasExpeditionsInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesExpeditions
    {
        /** @var HasExpeditionsInterface $service */
        $service = $context->gameService;

        return $service->getExpeditionsData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Expeditions');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        if ($data->isEmpty()) {
            return new SummaryLine($this->getLabel(), 'None dispatched', $this->getIcon());
        }

        // Null when none is out, or when the return of one of them is unknown
        $secondsUntilAllBack = $data->getSecondsUntilAllBack($this->clock->now());
        // Cached notes whose last return has passed: every expedition is back by now, whatever they still say
        if (!$data->allDone() && $secondsUntilAllBack === 0) {
            return new SummaryLine($this->getLabel(), sprintf('%d/%d back', $data->count(), $data->count()), $this->getIcon(), attention: true);
        }

        $value = sprintf('%d/%d back', $data->countDone(), $data->count());
        if ($secondsUntilAllBack !== null) {
            $value .= ' · all back in ' . $this->durationFormatter->format($secondsUntilAllBack, 2);
        }

        // As the expeditions reminder: when every expedition is back
        return new SummaryLine($this->getLabel(), $value, $this->getIcon(), attention: $data->allDone());
    }
}
