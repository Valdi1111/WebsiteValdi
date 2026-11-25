<?php

namespace App\GamesBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\CheckInData;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Today's daily check-in, read without claiming it. It asks for attention only when the site does not claim it for
 * the user: with auto check-in on, an unclaimed reward is expected until the scheduled task runs.
 *
 * @implements ProfileSectionInterface<CheckInData>
 */
#[AsTaggedItem(priority: 40)]
class CheckInSection implements ProfileSectionInterface
{
    public function __construct(
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly ProfileSettingsReader $settingsReader,
    ) {
    }

    public function getKey(): string
    {
        return 'check_in';
    }

    public function getLabel(): string
    {
        return 'Check-in';
    }

    public function getIcon(): string
    {
        return '🎁';
    }

    public function supports(GameProfileContext $context): bool
    {
        return $context->accountData !== null && $context->gameService instanceof HasCheckInStatusInterface;
    }

    /**
     * A call of its own (HoYoLAB check-in info, SKPort attendance), not the notes.
     */
    public function usesNotes(): bool
    {
        return false;
    }

    public function inSummary(): bool
    {
        return true;
    }

    public function collect(GameProfileContext $context): CheckInData
    {
        /** @var HasCheckInStatusInterface $service */
        $service = $context->gameService;
        $status = $service->getCheckInStatus($context->accountData, $context->fresh);

        return new CheckInData($status, ($this->settingsReader->read($context)['check_in'] ?? null)?->enabled ?? false);
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        $embed = $this->embedFactory->create($profile, 'Check-in')
            ->addField('Today', $data->status->signedToday ? 'Claimed' : 'Not claimed yet', icon: '🎁', inline: true);
        if ($data->status->totalDays !== null) {
            $embed->addField('Days Claimed', (string) $data->status->totalDays, icon: '📆', inline: true);
        }

        return $embed->addField('Auto Check-In', $data->autoCheckIn ? 'On' : 'Off', icon: '🔁', inline: true);
    }

    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        if ($data->status->signedToday) {
            $value = 'Done' . ($data->status->totalDays !== null ? sprintf(' · day %d', $data->status->totalDays) : '');

            return new SummaryLine($this->getLabel(), $value, $this->getIcon());
        }

        return new SummaryLine(
            $this->getLabel(),
            'Not done today' . ($data->autoCheckIn ? ' · auto check-in on' : ''),
            $this->getIcon(),
            attention: !$data->autoCheckIn,
        );
    }
}
