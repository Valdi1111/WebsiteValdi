<?php

namespace App\HoyoverseBundle\Model\Notes;

use App\CoreBundle\Model\LabeledInterface;

class GameNotesStateMetric implements GameNotesMetricInterface
{
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

    public function isDone(): bool
    {
        return $this->getCurrentValue() === $this->getTargetValue();
    }

    public function getFormattedOutput(): string
    {
        $currentValue = $this->getCurrentValue();
        if ($currentValue instanceof LabeledInterface) {
            return $currentValue->getLabel();
        }
        return $currentValue?->value;
    }

}