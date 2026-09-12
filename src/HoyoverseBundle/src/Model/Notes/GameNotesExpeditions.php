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
        return $this->forAll(static fn (int $i, GameNotesExpedition $expedition) => $expedition->isDone());
    }

}