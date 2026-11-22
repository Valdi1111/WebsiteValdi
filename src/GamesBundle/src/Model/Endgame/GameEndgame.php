<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * Player's progress in one endgame mode for the current period (e.g. Shiyu Defense, Deadly Assault).
 *
 * The game service decides what "played" means and measures the progress towards the maximum (e.g. stages at
 * S rank, stars): this class only compares them and checks the end of the period.
 */
class GameEndgame
{
    private const int SECONDS_PER_DAY = 86400;

    private ?string $name = null;

    private bool $unlocked = true;

    private ?\DateTimeImmutable $endTime = null;

    private bool $played = false;

    private ?int $progress = null;

    private ?int $maxProgress = null;

    private ?string $progressUnit = null;

    /**
     * @var list<GameEndgameStage>
     */
    private array $stages = [];

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
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
     * End of the period as a real instant (null when the game did not say).
     */
    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function isPlayed(): bool
    {
        return $this->played;
    }

    public function setPlayed(bool $played): self
    {
        $this->played = $played;
        return $this;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function setProgress(?int $progress): self
    {
        $this->progress = $progress;
        return $this;
    }

    public function getMaxProgress(): ?int
    {
        return $this->maxProgress;
    }

    public function setMaxProgress(?int $maxProgress): self
    {
        $this->maxProgress = $maxProgress;
        return $this;
    }

    public function getProgressUnit(): ?string
    {
        return $this->progressUnit;
    }

    public function setProgressUnit(?string $progressUnit): self
    {
        $this->progressUnit = $progressUnit;
        return $this;
    }

    /**
     * @return list<GameEndgameStage>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    public function addStage(GameEndgameStage $stage): self
    {
        $this->stages[] = $stage;
        return $this;
    }

    public function isMaxed(): bool
    {
        if ($this->getProgress() === null || $this->getMaxProgress() === null) {
            return false;
        }

        return $this->getProgress() >= $this->getMaxProgress();
    }

    /**
     * Whether the player should be reminded of this mode: while it has not been played and, when
     * $untilMaxed is set, also while it is below the maximum. A locked mode never needs a reminder.
     */
    public function needsReminder(bool $untilMaxed): bool
    {
        if (!$this->isUnlocked()) {
            return false;
        }

        if (!$this->isPlayed()) {
            return true;
        }

        return $untilMaxed && !$this->isMaxed();
    }

    /**
     * Whether the period is still running at $now and ends within $days days (24-hour days, so the result does
     * not depend on time zones). False when the end is unknown.
     */
    public function endsWithin(\DateTimeInterface $now, int $days): bool
    {
        if ($this->getEndTime() === null) {
            return false;
        }

        $remaining = $this->getEndTime()->getTimestamp() - $now->getTimestamp();

        return $remaining > 0 && $remaining <= $days * self::SECONDS_PER_DAY;
    }

    /**
     * Progress towards the maximum, e.g. "7/9 ★".
     */
    public function getFormattedProgress(): string
    {
        return trim(sprintf('%d/%d %s', $this->getProgress() ?? 0, $this->getMaxProgress() ?? 0, $this->getProgressUnit() ?? ''));
    }
}
