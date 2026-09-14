<?php

namespace App\HoyoverseBundle\Model\Notes;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @extends ArrayCollection<int, GameNotesProgressMetric>
 */
class GameNotesWeeklies extends ArrayCollection
{

    public function addWeekly(GameNotesMetricInterface $metric): self
    {
        $this->add($metric);
        return $this;
    }

    public function allDone(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }
        return $this->forAll(static fn (int $i, GameNotesProgressMetric $metric) => $metric->isDone());
    }

}