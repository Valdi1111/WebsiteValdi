<?php

namespace App\GamesBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Status\AbstractNotesSection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesStamina>
 */
#[AsTaggedItem(priority: 100)]
class StaminaSection extends AbstractNotesSection
{
    public function __construct(
        private readonly StaminaPresenter $presenter,
        private readonly DurationFormatter $durationFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return 'stamina';
    }

    public function getLabel(): string
    {
        return 'Stamina';
    }

    public function getIcon(): string
    {
        return '🔋';
    }

    protected function getCapability(): string
    {
        return HasStaminaInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesStamina
    {
        /** @var HasStaminaInterface $service */
        $service = $context->gameService;

        return $service->getStaminaData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Stamina');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        if ($data->getCurrentStamina() === null || $data->getMaxStamina() === null) {
            return new SummaryLine($this->getLabel(), 'Unknown', $this->getIcon());
        }

        $secondsUntilFull = $data->getSecondsUntilFull($this->clock->now());
        // Cached notes whose full instant has passed: the stamina is full by now, whatever they still say
        if (!$data->isFull() && $data->getFullAt() !== null && $secondsUntilFull === 0) {
            return new SummaryLine($this->getLabel(), sprintf('%d/%d · full', $data->getMaxStamina(), $data->getMaxStamina()), $this->getIcon(), attention: true);
        }

        $value = sprintf('%d/%d', $data->getCurrentStamina(), $data->getMaxStamina());
        if ($data->isFull()) {
            $value .= ' · full';
        } elseif ($secondsUntilFull !== null) {
            $value .= ' · full in ' . $this->durationFormatter->format($secondsUntilFull, 2);
        }

        return new SummaryLine($this->getLabel(), $value, $this->getIcon(), attention: $data->reachesThreshold($profile->getStaminaThreshold()));
    }
}
