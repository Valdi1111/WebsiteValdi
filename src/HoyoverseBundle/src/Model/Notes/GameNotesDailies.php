<?php

namespace App\HoyoverseBundle\Model\Notes;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @extends ArrayCollection<int, GameNotesMetricInterface>
 */
class GameNotesDailies extends ArrayCollection
{

    public function addDaily(GameNotesMetricInterface $metric): self
    {
        $this->add($metric);
        return $this;
    }

    public function allDone(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }
        return $this->forAll(static fn (int $i, GameNotesMetricInterface $metric) => $metric->isDone());
    }

}