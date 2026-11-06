<?php

namespace App\GryphlineBundle\Model\Notes;

use App\CoreBundle\Model\GameNotes\GameNotes;
use Symfony\Component\Serializer\Attribute\SerializedPath;

class EndfieldNotes implements GameNotes
{
    // =========================================================================
    // Stamina (Dungeon)
    // =========================================================================

    #[SerializedPath('[dungeon][curStamina]')]
    private int|string|null $curStamina = null;

    #[SerializedPath('[dungeon][maxStamina]')]
    private int|string|null $maxStamina = null;

    #[SerializedPath('[dungeon][maxTs]')]
    private int|string|null $maxTs = null;

    // =========================================================================
    // Dailies (Daily Mission)
    // =========================================================================

    #[SerializedPath('[dailyMission][dailyActivation]')]
    private int|string|null $dailyActivation = null;

    #[SerializedPath('[dailyMission][maxDailyActivation]')]
    private int|string|null $maxDailyActivation = null;

    // =========================================================================
    // Weeklies (Weekly Mission)
    // =========================================================================

    #[SerializedPath('[weeklyMission][score]')]
    private int|string|null $weeklyScore = null;

    #[SerializedPath('[weeklyMission][total]')]
    private int|string|null $weeklyTotal = null;

    // =========================================================================
    // Battle Pass (BP System)
    // =========================================================================

    #[SerializedPath('[bpSystem][curLevel]')]
    private int|string|null $bpCurLevel = null;

    #[SerializedPath('[bpSystem][maxLevel]')]
    private int|string|null $bpMaxLevel = null;

    // =========================================================================
    // Getters & Setters
    // =========================================================================

    public function getCurStamina(): ?int
    {
        return $this->curStamina !== null ? (int) $this->curStamina : null;
    }

    public function setCurStamina(int|string|null $curStamina): self
    {
        $this->curStamina = $curStamina;
        return $this;
    }

    public function getMaxStamina(): ?int
    {
        return $this->maxStamina !== null ? (int) $this->maxStamina : null;
    }

    public function setMaxStamina(int|string|null $maxStamina): self
    {
        $this->maxStamina = $maxStamina;
        return $this;
    }

    public function getMaxTs(): ?int
    {
        return $this->maxTs !== null ? (int) $this->maxTs : null;
    }

    public function setMaxTs(int|string|null $maxTs): self
    {
        $this->maxTs = $maxTs;
        return $this;
    }

    public function getStaminaRecoveryTime(): ?int
    {
        $maxTs = $this->getMaxTs();
        return $maxTs !== null ? max(0, $maxTs - time()) : null;
    }

    public function getDailyActivation(): ?int
    {
        return $this->dailyActivation !== null ? (int) $this->dailyActivation : null;
    }

    public function setDailyActivation(int|string|null $dailyActivation): self
    {
        $this->dailyActivation = $dailyActivation;
        return $this;
    }

    public function getMaxDailyActivation(): ?int
    {
        return $this->maxDailyActivation !== null ? (int) $this->maxDailyActivation : null;
    }

    public function setMaxDailyActivation(int|string|null $maxDailyActivation): self
    {
        $this->maxDailyActivation = $maxDailyActivation;
        return $this;
    }

    public function getWeeklyScore(): ?int
    {
        return $this->weeklyScore !== null ? (int) $this->weeklyScore : null;
    }

    public function setWeeklyScore(int|string|null $weeklyScore): self
    {
        $this->weeklyScore = $weeklyScore;
        return $this;
    }

    public function getWeeklyTotal(): ?int
    {
        return $this->weeklyTotal !== null ? (int) $this->weeklyTotal : null;
    }

    public function setWeeklyTotal(int|string|null $weeklyTotal): self
    {
        $this->weeklyTotal = $weeklyTotal;
        return $this;
    }

    public function getBpCurLevel(): ?int
    {
        return $this->bpCurLevel !== null ? (int) $this->bpCurLevel : null;
    }

    public function setBpCurLevel(int|string|null $bpCurLevel): self
    {
        $this->bpCurLevel = $bpCurLevel;
        return $this;
    }

    public function getBpMaxLevel(): ?int
    {
        return $this->bpMaxLevel !== null ? (int) $this->bpMaxLevel : null;
    }

    public function setBpMaxLevel(int|string|null $bpMaxLevel): self
    {
        $this->bpMaxLevel = $bpMaxLevel;
        return $this;
    }
}
