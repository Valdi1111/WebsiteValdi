<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Period of a Honkai: Star Rail endgame mode: one of the groups[] of Memory of Chaos, Pure Fiction and Apocalyptic
 * Shadow, or the group of a run of Anomaly Arbitration.
 *
 * "status" is not read: it cannot be trusted (the current Anomaly Arbitration run says "End"), so the current period
 * is the one whose dates contain the current time.
 */
class HonkaiStarRailEndgamePeriod
{
    /** Start of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedName('begin_time')]
    private ?\DateTimeImmutable $beginTime = null;

    /** End of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedName('end_time')]
    private ?\DateTimeImmutable $endTime = null;

    public function getBeginTime(): ?\DateTimeImmutable
    {
        return $this->beginTime;
    }

    public function setBeginTime(?\DateTimeImmutable $beginTime): static
    {
        $this->beginTime = $beginTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }
}
