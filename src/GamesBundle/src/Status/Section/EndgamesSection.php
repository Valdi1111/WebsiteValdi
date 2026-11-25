<?php

namespace App\GamesBundle\Status\Section;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Endgame\EndgameModeStatus;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameSettings;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\EndgamesData;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GamesBundle\Service\Endgame\EndgameStatusBuilder;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Every endgame mode of the game with the progress of the current period, measured against the targets of the profile
 * (its endgame settings). Each mode is a battle record call (cached 30 minutes), so the compact view leaves it out.
 *
 * A mode that cannot be read, or has no current period, stays out of the full view with a line saying so; the section
 * fails only when every mode failed (an account-wide problem).
 *
 * @implements ProfileSectionInterface<EndgamesData>
 */
#[AsTaggedItem(priority: 30)]
class EndgamesSection implements ProfileSectionInterface
{
    public function __construct(
        private readonly EndgameStatusBuilder $builder,
        private readonly EndgamesPresenter $presenter,
        private readonly ProfileSettingsReader $settingsReader,
        private readonly DurationFormatter $durationFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return 'endgames';
    }

    public function getLabel(): string
    {
        return 'Endgames';
    }

    public function getIcon(): string
    {
        return '🎯';
    }

    public function supports(GameProfileContext $context): bool
    {
        return $context->accountData !== null && $context->gameService instanceof HasEndgamesInterface;
    }

    public function usesNotes(): bool
    {
        return false;
    }

    public function inSummary(): bool
    {
        return false;
    }

    public function collect(GameProfileContext $context): EndgamesData
    {
        /** @var HasEndgamesInterface $service */
        $service = $context->gameService;
        $readout = $this->builder->read($service, $context->accountData, null, $context->fresh);
        if ($readout->allFailed()) {
            throw $readout->getErrors()[0];
        }

        $settings = ($this->settingsReader->read($context)['endgames'] ?? null)?->extra['endgamesSettings'] ?? null;

        return new EndgamesData($readout, EndgameSettings::resolve($service::getEndgameDefinitions(), $settings));
    }

    public function present(GameProfileInterface $profile, object $data): UniversalEmbed
    {
        $modes = [];
        $notes = [];
        foreach ($data->readout->modes as $mode) {
            $endTime = $mode->getEndTime();
            if (!$mode->isSuccessful()) {
                $notes[] = sprintf('%s: %s', $mode->definition->name, ProfileSummaryPresenter::UNAVAILABLE);
            } elseif ($endTime === null) {
                $notes[] = sprintf('%s: no current period', $mode->definition->name);
            } else {
                $modes[] = new EndgameModeStatus($mode->definition, $mode->endgames, $endTime);
            }
        }

        return $this->presenter->present($profile, $modes, $this->clock->now(), 'Endgames', $notes !== [] ? implode("\n", $notes) : null);
    }

    /**
     * "<at target>/<modes> at target", with the end of the first period still to play; modes the profile turned off
     * entirely, or still locked, are not counted. Attention when a mode would get a reminder (not played, or below its full clear target).
     */
    public function summarize(GameProfileInterface $profile, object $data): SummaryLine
    {
        $now = $this->clock->now();
        $total = 0;
        $done = 0;
        $unavailable = 0;
        $nextEnd = null;
        foreach ($data->readout->modes as $mode) {
            $endTime = $mode->getEndTime();
            if (!$mode->isSuccessful()) {
                $unavailable++;
                continue;
            }
            if ($endTime === null) {
                continue;
            }

            $targets = $data->targets[$mode->definition->key] ?? [];
            // Variants the profile follows and can play: a locked one has nothing to reach yet
            $active = array_filter($mode->endgames, static fn ($endgame): bool => $endgame->isUnlocked()
                && isset($targets[$endgame->getVariant()]) && $targets[$endgame->getVariant()]->reminder !== EndgameReminder::Off);
            if ($active === []) {
                continue;
            }

            $total++;
            $needsAction = array_filter($active, static fn ($endgame): bool => $endgame->needsReminder($targets[$endgame->getVariant()])) !== [];
            if (!$needsAction) {
                $done++;
            } elseif ($nextEnd === null || $endTime < $nextEnd) {
                $nextEnd = $endTime;
            }
        }

        if ($total === 0) {
            $value = $unavailable > 0 ? ProfileSummaryPresenter::UNAVAILABLE : 'Nothing to play';

            return new SummaryLine($this->getLabel(), $value, $this->getIcon());
        }

        $value = sprintf('%d/%d at target', $done, $total);
        if ($nextEnd !== null) {
            $value .= ' · next ends in ' . $this->durationFormatter->format(max(0, $nextEnd->getTimestamp() - $now->getTimestamp()), 2);
        }
        if ($unavailable > 0) {
            $value .= sprintf(' · %d unavailable', $unavailable);
        }

        return new SummaryLine($this->getLabel(), $value, $this->getIcon(), attention: $nextEnd !== null);
    }
}
