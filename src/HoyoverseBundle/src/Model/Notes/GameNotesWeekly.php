<?php

namespace App\HoyoverseBundle\Model\Notes;

class GameNotesWeekly
{
    private ?string $name = null;

    private ?int $currentValue = null;

    private ?int $maxValue = null;

    private bool $unlocked = true;

    private GameNotesWeeklyCheckType $checkType = GameNotesWeeklyCheckType::CURRENT_EQUALS_MAX;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCurrentValue(): ?int
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?int $currentValue): self
    {
        $this->currentValue = $currentValue;
        return $this;
    }

    public function getMaxValue(): ?int
    {
        return $this->maxValue;
    }

    public function setMaxValue(?int $maxValue): self
    {
        $this->maxValue = $maxValue;
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

    public function getCheckType(): GameNotesWeeklyCheckType
    {
        return $this->checkType;
    }

    public function setCheckType(GameNotesWeeklyCheckType $checkType): self
    {
        $this->checkType = $checkType;
        return $this;
    }

    public function isDone(): bool
    {
        return match ($this->getCheckType()) {
            GameNotesWeeklyCheckType::CURRENT_EQUALS_MAX => $this->getCurrentValue() == $this->getMaxValue(),
            GameNotesWeeklyCheckType::CURRENT_EQUALS_ZERO => $this->getCurrentValue() == 0,
        };
    }

}