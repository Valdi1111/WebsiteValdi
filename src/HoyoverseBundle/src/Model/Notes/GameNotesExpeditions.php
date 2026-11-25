<?php

namespace App\HoyoverseBundle\Model\Notes;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @extends ArrayCollection<int, GameNotesExpedition>
 */
class GameNotesExpeditions extends ArrayCollection
{

    public function addExpedition(
        ?string $avatar,
        ?string $status,
        ?int    $remainingTime,
        ?\DateTimeImmutable $finishAt = null,
    ): self
    {
        $this->add(new GameNotesExpedition()
            ->setAvatar($avatar)
            ->setStatus($status)
            ->setRemainingTime($remainingTime)
            ->setFinishAt($finishAt));
        return $this;
    }

    public function allDone(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }
        return $this->forAll(static fn ($_, GameNotesExpedition $expedition) => $expedition->isDone());
    }

    public function countDone(): int
    {
        return $this->filter(static fn (GameNotesExpedition $expedition) => $expedition->isDone())->count();
    }

    /**
     * Seconds until the last expedition still out is back. Null when none is out, or when one of them has no
     * remaining time.
     */
    public function getCompletionTime(): ?int
    {
        $times = [];
        foreach ($this as $expedition) {
            if ($expedition->isDone()) {
                continue;
            }
            if ($expedition->getRemainingTime() === null) {
                return null;
            }
            $times[] = $expedition->getRemainingTime();
        }

        return $times !== [] ? max($times) : null;
    }

    /**
     * Seconds at $now until the last expedition still out is back: from the instants of getFinishAt() when every
     * one has it, else getCompletionTime() (relative to the fetch). Null as for getCompletionTime().
     */
    public function getSecondsUntilAllBack(\DateTimeInterface $now): ?int
    {
        $finishAt = [];
        foreach ($this as $expedition) {
            if ($expedition->isDone()) {
                continue;
            }
            if ($expedition->getFinishAt() === null) {
                return $this->getCompletionTime();
            }
            $finishAt[] = $expedition->getFinishAt()->getTimestamp();
        }

        return $finishAt !== [] ? max(0, max($finishAt) - $now->getTimestamp()) : null;
    }

    /**
     * Whether every expedition still out in the notes is back by now: cached notes whose last return has passed still
     * show them out, and views show them back, as they are in the game.
     */
    public function haveAllReturnedBy(\DateTimeInterface $now): bool
    {
        return !$this->isEmpty() && !$this->allDone() && $this->getSecondsUntilAllBack($now) === 0;
    }

}
