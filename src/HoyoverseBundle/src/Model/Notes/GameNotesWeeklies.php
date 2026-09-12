<?php

namespace App\HoyoverseBundle\Model\Notes;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @extends ArrayCollection<int, GameNotesWeekly>
 */
class GameNotesWeeklies extends ArrayCollection
{

    public function addWeekly(
        string                   $name,
        ?int                     $currentValue,
        ?int                     $maxValue,
        bool                     $unlocked = true,
        GameNotesWeeklyCheckType $checkType = GameNotesWeeklyCheckType::CURRENT_EQUALS_MAX
    ): self
    {
        $this->add(new GameNotesWeekly()
            ->setName($name)
            ->setCurrentValue($currentValue)
            ->setMaxValue($maxValue)
            ->setUnlocked($maxValue)
            ->setCheckType($checkType));
        return $this;
    }

}