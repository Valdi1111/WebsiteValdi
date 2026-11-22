<?php

namespace App\GamesBundle\Model\Endgame;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @extends ArrayCollection<int, GameEndgame>
 */
class GameEndgames extends ArrayCollection
{
    public function addEndgame(GameEndgame $endgame): self
    {
        $this->add($endgame);
        return $this;
    }
}
