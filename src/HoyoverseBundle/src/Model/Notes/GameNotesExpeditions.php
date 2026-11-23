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
    ): self
    {
        $this->add(new GameNotesExpedition()
            ->setAvatar($avatar)
            ->setStatus($status)
            ->setRemainingTime($remainingTime));
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

}
