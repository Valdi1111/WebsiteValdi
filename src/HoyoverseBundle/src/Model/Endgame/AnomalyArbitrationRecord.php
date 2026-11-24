<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One run of Anomaly Arbitration (challenge_peak_records[]): its period, the three Knights and the King.
 *
 * The rules of the Endgames Reminder (played, maximum, Plight) are the constants and methods below. Plight is the
 * hard mode of the King: pass/fail, without stars.
 */
class AnomalyArbitrationRecord
{
    /** Stars of the Knights at the maximum: three Knights with three stars each */
    public const int KNIGHTS_MAX_STARS = 9;

    /** Stars of the King at the maximum */
    public const int KING_MAX_STARS = 3;

    #[SerializedName('group')]
    private ?HonkaiStarRailEndgamePeriod $period = null;

    #[SerializedName('has_challenge_record')]
    private ?bool $hasChallengeRecord = null;

    #[SerializedName('boss_record')]
    private ?AnomalyArbitrationBossRecord $bossRecord = null;

    /** Stars of the three Knights of the run */
    #[SerializedName('mob_stars')]
    private int|string|null $mobStars = null;

    /** Stars of the King of the run */
    #[SerializedName('boss_stars')]
    private int|string|null $bossStars = null;

    public function getPeriod(): ?HonkaiStarRailEndgamePeriod
    {
        return $this->period;
    }

    public function setPeriod(?HonkaiStarRailEndgamePeriod $period): static
    {
        $this->period = $period;
        return $this;
    }

    public function getHasChallengeRecord(): ?bool
    {
        return $this->hasChallengeRecord;
    }

    public function setHasChallengeRecord(?bool $hasChallengeRecord): static
    {
        $this->hasChallengeRecord = $hasChallengeRecord;
        return $this;
    }

    public function getBossRecord(): ?AnomalyArbitrationBossRecord
    {
        return $this->bossRecord;
    }

    public function setBossRecord(?AnomalyArbitrationBossRecord $bossRecord): static
    {
        $this->bossRecord = $bossRecord;
        return $this;
    }

    public function getMobStars(): int|string|null
    {
        return $this->mobStars;
    }

    public function setMobStars(int|string|null $mobStars): static
    {
        $this->mobStars = $mobStars;
        return $this;
    }

    public function getBossStars(): int|string|null
    {
        return $this->bossStars;
    }

    public function setBossStars(int|string|null $bossStars): static
    {
        $this->bossStars = $bossStars;
        return $this;
    }

    public function getKnightStars(): int
    {
        return max(0, (int) ($this->mobStars ?? 0));
    }

    public function getKingStars(): int
    {
        return max(0, (int) ($this->bossStars ?? 0));
    }

    /**
     * Plight cleared = the record of the King is in hard mode (working assumption: a failed attempt leaves nothing
     * to read, so there is no other state to tell apart). The only place that decides it.
     */
    public function isPlightCleared(): bool
    {
        return $this->bossRecord?->getHardMode() === true;
    }

    /**
     * Normal variant played = a record of the run (Plight cleared also means the run was played).
     */
    public function isPlayed(): bool
    {
        return $this->hasChallengeRecord === true || $this->isPlightCleared();
    }

    /**
     * Normal variant at the maximum = every star of the Knights and the King, or Plight cleared.
     */
    public function isMaxed(): bool
    {
        return ($this->getKnightStars() >= self::KNIGHTS_MAX_STARS && $this->getKingStars() >= self::KING_MAX_STARS)
            || $this->isPlightCleared();
    }

    /**
     * Plight variant: played and maximum are the same thing.
     */
    public function isPlightPlayed(): bool
    {
        return $this->isPlightCleared();
    }

    public function isPlightMaxed(): bool
    {
        return $this->isPlightCleared();
    }
}
