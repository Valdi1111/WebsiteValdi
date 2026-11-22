<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * One scored part of an endgame mode (a stage, a boss...), listed by the Endgames Reminder when below the maximum.
 */
class GameEndgameStage
{
    private ?string $name = null;

    private ?int $score = null;

    private ?int $maxScore = null;

    /**
     * Game-specific result of the best attempt, e.g. a rating ("A") or stars ("2★").
     */
    private ?string $result = null;

    private bool $maxed = false;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;
        return $this;
    }

    public function getMaxScore(): ?int
    {
        return $this->maxScore;
    }

    public function setMaxScore(?int $maxScore): self
    {
        $this->maxScore = $maxScore;
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function isMaxed(): bool
    {
        return $this->maxed;
    }

    public function setMaxed(bool $maxed): self
    {
        $this->maxed = $maxed;
        return $this;
    }

    public function isPlayed(): bool
    {
        return ($this->getScore() ?? 0) > 0;
    }

    /**
     * E.g. "Stage 3: 21,500/50,000 (A)" or "Stage 2: not played".
     */
    public function getFormattedOutput(): string
    {
        $name = $this->getName() ?? 'Stage';
        if (!$this->isPlayed()) {
            return "{$name}: not played";
        }

        $output = sprintf('%s: %s', $name, number_format($this->getScore()));
        if ($this->getMaxScore() !== null) {
            $output .= '/' . number_format($this->getMaxScore());
        }
        if ($this->getResult() !== null && $this->getResult() !== '') {
            $output .= " ({$this->getResult()})";
        }

        return $output;
    }
}
