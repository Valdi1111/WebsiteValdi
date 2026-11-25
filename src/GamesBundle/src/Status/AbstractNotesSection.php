<?php

namespace App\GamesBundle\Status;

use App\GamesBundle\Model\GameNotes\GameNotesMetricInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;

/**
 * Section read from the real-time notes, supported by the games whose service has the capability interface.
 *
 * @template T of object
 * @implements ProfileSectionInterface<T>
 */
abstract class AbstractNotesSection implements ProfileSectionInterface
{
    /**
     * @return class-string capability interface the game service must implement (HasStaminaInterface...)
     */
    abstract protected function getCapability(): string;

    public function supports(GameProfileContext $context): bool
    {
        return $context->accountData !== null && is_a($context->gameService, $this->getCapability());
    }

    public function usesNotes(): bool
    {
        return true;
    }

    /**
     * Notes sections share one read per profile, so the compact view shows all of them.
     */
    public function inSummary(): bool
    {
        return true;
    }

    /**
     * Summary of a list of metrics (dailies, weeklies): the unlocked ones not done yet, or "Done".
     *
     * @param iterable<GameNotesMetricInterface> $metrics
     */
    protected function summarizeMetrics(iterable $metrics): SummaryLine
    {
        $unlocked = 0;
        $pending = [];
        foreach ($metrics as $metric) {
            if (!$metric->isUnlocked()) {
                continue;
            }
            $unlocked++;
            if (!$metric->isDone()) {
                $pending[] = trim(sprintf('%s %s', $metric->getName() ?? '', $metric->getFormattedOutput()));
            }
        }

        return match (true) {
            $unlocked === 0 => new SummaryLine($this->getLabel(), 'None', $this->getIcon()),
            $pending === [] => new SummaryLine($this->getLabel(), 'Done', $this->getIcon()),
            default => new SummaryLine($this->getLabel(), implode(', ', $pending), $this->getIcon(), attention: true),
        };
    }
}
