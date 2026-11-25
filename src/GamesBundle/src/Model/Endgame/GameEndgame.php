<?php

namespace App\GamesBundle\Model\Endgame;

/**
 * Player's progress in one endgame mode, or one variant of it (e.g. Deadly Assault Hard), for the current period.
 *
 * The game decides what "played" and "maximum" mean, tells the highest difficulty completed when the variant has
 * levels, and writes the short progress lines shown in the notification (e.g. "Floor 5: 2/3 S", "7/9 ★"): this class
 * only applies the profile setting and checks the end of the period.
 */
class GameEndgame
{
    private const int SECONDS_PER_DAY = 86400;

    private ?string $name = null;

    /** Key of the EndgameVariant this result belongs to */
    private string $variant = EndgameVariant::DEFAULT_KEY;

    private bool $unlocked = true;

    private ?\DateTimeImmutable $endTime = null;

    private bool $played = false;

    private bool $maxed = false;

    /** Key of the highest EndgameLevel completed in the period (variants with levels only) */
    private ?string $completedLevel = null;

    /**
     * @var list<array{text: string, icon: ?string}>
     */
    private array $progressLines = [];

    /** When the API answered (the response may come from a cache), null when unknown */
    private ?\DateTimeImmutable $fetchedAt = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function setVariant(string $variant): self
    {
        $this->variant = $variant;
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

    public function isMaxed(): bool
    {
        return $this->maxed;
    }

    public function setMaxed(bool $maxed): self
    {
        $this->maxed = $maxed;
        return $this;
    }

    public function getCompletedLevel(): ?string
    {
        return $this->completedLevel;
    }

    /**
     * @param string|null $completedLevel key of the highest level completed, null when none is (or it is unknown)
     */
    public function setCompletedLevel(?string $completedLevel): self
    {
        $this->completedLevel = $completedLevel;
        return $this;
    }

    /**
     * Short lines describing the progress of a played mode, each with its icon in front when it has one, e.g.
     * ["⚔️  Floor 4: S", "⚔️  Floor 5: 2/3 S", "↳ Stage 2: A (21,000)"].
     *
     * @return list<string>
     */
    public function getProgressLines(): array
    {
        return array_map(
            static fn (array $line) => $line['icon'] !== null ? "{$line['icon']}\u{00A0}\u{00A0}{$line['text']}" : $line['text'],
            $this->progressLines
        );
    }

    /**
     * @param string|null $icon shown in front of the line (e.g. "⚔️"); a mode whose lines have icons is shown without
     *                          an icon of its own, so that the lines stay aligned
     */
    public function addProgressLine(string $line, ?string $icon = null): self
    {
        $this->progressLines[] = ['text' => $line, 'icon' => $icon];
        return $this;
    }

    /**
     * Whether the progress lines carry their own icons.
     */
    public function hasProgressIcons(): bool
    {
        foreach ($this->progressLines as $line) {
            if ($line['icon'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the API answered the request(s) this result was built from, the oldest when there are several; null when
     * unknown.
     */
    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(?\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;
        return $this;
    }

    /**
     * Whether the player should be reminded of this mode with the given profile setting. A locked mode never needs a
     * reminder, a mode not played always does unless the setting is off; a played one only with Full Clear (or a
     * level) while the target is not reached.
     */
    public function needsReminder(EndgameTarget $target): bool
    {
        if ($target->reminder === EndgameReminder::Off || !$this->isUnlocked()) {
            return false;
        }

        if (!$this->isPlayed()) {
            return true;
        }

        return $target->reminder === EndgameReminder::FullClear && !$this->isTargetReached($target);
    }

    /**
     * Whether the progress meets the target: with a level, the completed level is at least the chosen one (an unknown
     * or missing completed level never is); without, the maximum.
     */
    public function isTargetReached(EndgameTarget $target): bool
    {
        if ($target->level === null) {
            return $this->isMaxed();
        }

        return $target->isLevelReached($this->getCompletedLevel());
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
}
