<?php

namespace App\HoyoverseBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Status\AbstractNotesSection;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Presenter\RealmCurrencyPresenter;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesRealm>
 */
#[AsTaggedItem(priority: 60)]
class RealmCurrencySection extends AbstractNotesSection
{
    public function __construct(
        private readonly RealmCurrencyPresenter $presenter,
        private readonly DurationFormatter $durationFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return 'realm_currency';
    }

    public function getLabel(): string
    {
        return 'Realm Currency';
    }

    public function getIcon(): string
    {
        return '💰';
    }

    protected function getCapability(): string
    {
        return HasRealmCurrencyInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesRealm
    {
        /** @var HasRealmCurrencyInterface $service */
        $service = $context->gameService;

        return $service->getRealmData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Realm Currency');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        // Checked first: isFull() compares with ==, so a missing current and max would read as full
        if ($data->getCurrentCoin() === null || $data->getMaxCoin() === null) {
            return new SummaryLine($this->getLabel(), 'Unknown', $this->getIcon());
        }

        $secondsUntilFull = $data->getSecondsUntilFull($this->clock->now());
        // Cached notes whose full instant has passed: the realm currency is full by now, whatever they still say
        if (!$data->isFull() && $data->getFullAt() !== null && $secondsUntilFull === 0) {
            return new SummaryLine($this->getLabel(), sprintf('%d/%d · full', $data->getMaxCoin(), $data->getMaxCoin()), $this->getIcon(), attention: true);
        }

        $value = sprintf('%d/%d', $data->getCurrentCoin(), $data->getMaxCoin());
        if ($data->isFull()) {
            $value .= ' · full';
        } elseif ($secondsUntilFull !== null) {
            $value .= ' · full in ' . $this->durationFormatter->format($secondsUntilFull, 2);
        }

        // As the realm currency reminder: the threshold of the profile, or full when it has none
        $attention = $profile instanceof HoyoverseGameProfile
            ? $data->reachesThreshold($profile->getRealmCurrencyThreshold())
            : $data->isFull();

        return new SummaryLine($this->getLabel(), $value, $this->getIcon(), attention: $attention);
    }
}
