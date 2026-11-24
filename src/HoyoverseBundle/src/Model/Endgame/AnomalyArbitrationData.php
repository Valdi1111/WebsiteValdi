<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Anomaly Arbitration (challenge_peak), requested with schedule_type=1: the current run. The player who never played
 * gets a record all the same, with has_challenge_record false, no boss_record and no mob_records.
 */
class AnomalyArbitrationData
{
    /**
     * @var AnomalyArbitrationRecord[]|null
     */
    #[SerializedName('challenge_peak_records')]
    private ?array $records = null;

    /**
     * @return AnomalyArbitrationRecord[]|null
     */
    public function getRecords(): ?array
    {
        return $this->records;
    }

    /**
     * @param AnomalyArbitrationRecord[]|null $records
     */
    public function setRecords(?array $records): static
    {
        $this->records = $records;
        return $this;
    }
}
