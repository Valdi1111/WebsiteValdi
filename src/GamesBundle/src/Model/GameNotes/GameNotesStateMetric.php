<?php

namespace App\GamesBundle\Model\GameNotes;

use App\CoreBundle\Model\LabeledInterface;

class GameNotesStateMetric implements GameNotesMetricInterface
{
    /**
     * Shown in place of the current state when the notes do not report it.
     */
    public const string UNKNOWN_OUTPUT = 'Unknown';

    private ?string $name = null;

    private ?\BackedEnum $currentValue = null;

    private ?\BackedEnum $targetValue = null;

    private bool $unlocked = true;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCurrentValue(): ?\BackedEnum
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?\BackedEnum $currentValue): self
    {
        $this->currentValue = $currentValue;
        return $this;
    }

    public function getTargetValue(): ?\BackedEnum
    {
        return $this->targetValue;
    }

    public function setTargetValue(?\BackedEnum $targetValue): self
    {
        $this->targetValue = $targetValue;
        return $this;
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked;
    }

    public function setUnlocked(bool $unlocked): self
    {
        $this->unlocked = $unlocked;
        return $this;
    }

    /**
     * An unknown current state is never done, even when the target is unknown too.
     */
    public function isDone(): bool
    {
        return $this->getCurrentValue() !== null && $this->getCurrentValue() === $this->getTargetValue();
    }

    public function getFormattedOutput(): string
    {
        $currentValue = $this->getCurrentValue();
        if ($currentValue === null) {
            return self::UNKNOWN_OUTPUT;
        }
        if ($currentValue instanceof LabeledInterface) {
            return $currentValue->getLabel();
        }

        return (string) $currentValue->value;
    }
}
