<?php

namespace App\HoyoverseBundle\Model\Notes;

class GameNotesProgressMetric implements GameNotesMetricInterface
{
    private ?string $name = null;

    private ?int $currentValue = null;

    private ?int $maxValue = null;

    private bool $unlocked = true;

    private GameNotesMetricCheckType $checkType = GameNotesMetricCheckType::CURRENT_EQUALS_MAX;

    private GameNotesMetricOutputType $outputType = GameNotesMetricOutputType::FRACTION;

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

    public function getCheckType(): GameNotesMetricCheckType
    {
        return $this->checkType;
    }

    public function setCheckType(GameNotesMetricCheckType $checkType): self
    {
        $this->checkType = $checkType;
        return $this;
    }

    public function getOutputType(): GameNotesMetricOutputType
    {
        return $this->outputType;
    }

    public function setOutputType(GameNotesMetricOutputType $outputType): self
    {
        $this->outputType = $outputType;
        return $this;
    }

    public function isDone(): bool
    {
        if ($this->getCurrentValue() === null || $this->getMaxValue() === null) {
            return false;
        }

        return match ($this->getCheckType()) {
            GameNotesMetricCheckType::CURRENT_EQUALS_MAX => $this->getCurrentValue() >= $this->getMaxValue(),
            GameNotesMetricCheckType::CURRENT_EQUALS_ZERO => $this->getCurrentValue() === 0,
        };
    }

    public function getFormattedOutput(): string
    {
        $normalized = $this->getNormalizedCurrentValue();
        $max = $this->getMaxValue() ?? 0;

        return match ($this->getOutputType()) {
            GameNotesMetricOutputType::FRACTION => "{$normalized}/{$max}",
            GameNotesMetricOutputType::PERCENTAGE => $max > 0 ? (int) round(($normalized / $max) * 100) . '%' : '0%',
            GameNotesMetricOutputType::STATUS => $this->isDone() ? 'Completed' : 'Incomplete',
        };
    }

    private function getNormalizedCurrentValue(): int
    {
        $current = $this->getCurrentValue() ?? 0;
        $max = $this->getMaxValue() ?? 0;

        return match ($this->getCheckType()) {
            GameNotesMetricCheckType::CURRENT_EQUALS_MAX => $current,
            GameNotesMetricCheckType::CURRENT_EQUALS_ZERO => max(0, $max - $current),
        };
    }

}