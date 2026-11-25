<?php

namespace App\HoyoverseBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Status\AbstractNotesSection;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Presenter\ShopStatusPresenter;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @extends AbstractNotesSection<GameNotesStateMetric>
 */
#[AsTaggedItem(priority: 50)]
class ShopStatusSection extends AbstractNotesSection
{
    public function __construct(
        private readonly ShopStatusPresenter $presenter,
    ) {
    }

    public function getKey(): string
    {
        return 'shop_status';
    }

    public function getLabel(): string
    {
        return 'Shop';
    }

    public function getIcon(): string
    {
        return '📼';
    }

    protected function getCapability(): string
    {
        return HasShopStatusInterface::class;
    }

    public function collect(GameProfileContext $context): GameNotesStateMetric
    {
        /** @var HasShopStatusInterface $service */
        $service = $context->gameService;

        return $service->getShopStatusData($context->accountData);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        return $this->presenter->present($profile, $data, 'Shop Status');
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        // Checked first: getFormattedOutput() cannot format a missing state, and an unknown state is not "done"
        if ($data->getCurrentValue() === null) {
            return new SummaryLine($this->getLabel(), 'Unknown', $this->getIcon());
        }

        // As the shop reminder: when the sale is done
        return new SummaryLine($this->getLabel(), $data->getFormattedOutput(), $this->getIcon(), attention: $data->isDone());
    }
}
