<?php

namespace App\HoyoverseBundle\Model\Notes;

use Symfony\Component\Serializer\Attribute\SerializedName;

class HonkaiStarRailNotes implements GameNotes, HasStaminaNotes, HasDailiesNotes, HasWeekliesNotes, HasExpeditionsNotes
{
    // =========================================================================
    // Stamina (Trailblaze Power & Reserve)
    // =========================================================================

    #[SerializedName('current_stamina')]
    private ?int $currentStamina = null;

    #[SerializedName('max_stamina')]
    private ?int $maxStamina = null;

    #[SerializedName('stamina_recover_time')]
    private ?int $staminaRecoverTime = null;

    #[SerializedName('current_reserve_stamina')]
    private ?int $currentReserveStamina = null;

    #[SerializedName('is_reserve_stamina_full')]
    private ?bool $isReserveStaminaFull = null;

    #[SerializedName('stamina_full_ts')]
    private ?int $staminaFullTs = null;

    // =========================================================================
    // Dailies (Daily Training)
    // =========================================================================

    #[SerializedName('current_train_score')]
    private ?int $currentTrainScore = null;

    #[SerializedName('max_train_score')]
    private ?int $maxTrainScore = null;

    // =========================================================================
    // Weeklies (Echo of War, Simulated Universe, Divergent Universe & Currency Wars)
    // =========================================================================

    #[SerializedName('weekly_cocoon_cnt')]
    private ?int $weeklyCocoonCnt = null;

    #[SerializedName('weekly_cocoon_limit')]
    private ?int $weeklyCocoonLimit = null;

    #[SerializedName('current_rogue_score')]
    private ?int $currentRogueScore = null;

    #[SerializedName('max_rogue_score')]
    private ?int $maxRogueScore = null;

    #[SerializedName('rogue_tourn_weekly_cur')]
    private ?int $rogueTournWeeklyCur = null;

    #[SerializedName('rogue_tourn_weekly_max')]
    private ?int $rogueTournWeeklyMax = null;

    #[SerializedName('rogue_tourn_weekly_unlocked')]
    private ?bool $rogueTournWeeklyUnlocked = null;

    #[SerializedName('rogue_tourn_exp_is_full')]
    private ?bool $rogueTournExpIsFull = null;

    #[SerializedName('grid_fight_weekly_cur')]
    private ?int $gridFightWeeklyCur = null;

    #[SerializedName('grid_fight_weekly_max')]
    private ?int $gridFightWeeklyMax = null;

    #[SerializedName('period_score')]
    private ?int $periodScore = null;

    #[SerializedName('period_max_score')]
    private ?int $periodMaxScore = null;

    // =========================================================================
    // Expeditions (Assignments)
    // =========================================================================

    #[SerializedName('accepted_epedition_num')]
    private ?int $acceptedExpeditionNum = null;

    #[SerializedName('total_expedition_num')]
    private ?int $totalExpeditionNum = null;

    /**
     * @var HonkaiStarRailExpedition[]|null
     */
    #[SerializedName('expeditions')]
    private ?array $expeditions = null;

    // =========================================================================
    // Timestamps / Altro
    // =========================================================================

    #[SerializedName('current_ts')]
    private ?int $currentTs = null;

    // =========================================================================
    // Getters & Setters
    // =========================================================================

    public function getCurrentStamina(): ?int
    {
        return $this->currentStamina;
    }

    public function setCurrentStamina(?int $currentStamina): self
    {
        $this->currentStamina = $currentStamina;
        return $this;
    }

    public function getMaxStamina(): ?int
    {
        return $this->maxStamina;
    }

    public function setMaxStamina(?int $maxStamina): self
    {
        $this->maxStamina = $maxStamina;
        return $this;
    }

    public function getStaminaRecoverTime(): ?int
    {
        return $this->staminaRecoverTime;
    }

    public function setStaminaRecoverTime(?int $staminaRecoverTime): self
    {
        $this->staminaRecoverTime = $staminaRecoverTime;
        return $this;
    }

    public function getCurrentReserveStamina(): ?int
    {
        return $this->currentReserveStamina;
    }

    public function setCurrentReserveStamina(?int $currentReserveStamina): self
    {
        $this->currentReserveStamina = $currentReserveStamina;
        return $this;
    }

    public function isReserveStaminaFull(): ?bool
    {
        return $this->isReserveStaminaFull;
    }

    public function setIsReserveStaminaFull(?bool $isReserveStaminaFull): self
    {
        $this->isReserveStaminaFull = $isReserveStaminaFull;
        return $this;
    }

    public function getStaminaFullTs(): ?int
    {
        return $this->staminaFullTs;
    }

    public function setStaminaFullTs(?int $staminaFullTs): self
    {
        $this->staminaFullTs = $staminaFullTs;
        return $this;
    }

    public function getCurrentTrainScore(): ?int
    {
        return $this->currentTrainScore;
    }

    public function setCurrentTrainScore(?int $currentTrainScore): self
    {
        $this->currentTrainScore = $currentTrainScore;
        return $this;
    }

    public function getMaxTrainScore(): ?int
    {
        return $this->maxTrainScore;
    }

    public function setMaxTrainScore(?int $maxTrainScore): self
    {
        $this->maxTrainScore = $maxTrainScore;
        return $this;
    }

    public function getWeeklyCocoonCnt(): ?int
    {
        return $this->weeklyCocoonCnt;
    }

    public function setWeeklyCocoonCnt(?int $weeklyCocoonCnt): self
    {
        $this->weeklyCocoonCnt = $weeklyCocoonCnt;
        return $this;
    }

    public function getWeeklyCocoonLimit(): ?int
    {
        return $this->weeklyCocoonLimit;
    }

    public function setWeeklyCocoonLimit(?int $weeklyCocoonLimit): self
    {
        $this->weeklyCocoonLimit = $weeklyCocoonLimit;
        return $this;
    }

    public function getCurrentRogueScore(): ?int
    {
        return $this->currentRogueScore;
    }

    public function setCurrentRogueScore(?int $currentRogueScore): self
    {
        $this->currentRogueScore = $currentRogueScore;
        return $this;
    }

    public function getMaxRogueScore(): ?int
    {
        return $this->maxRogueScore;
    }

    public function setMaxRogueScore(?int $maxRogueScore): self
    {
        $this->maxRogueScore = $maxRogueScore;
        return $this;
    }

    public function getRogueTournWeeklyCur(): ?int
    {
        return $this->rogueTournWeeklyCur;
    }

    public function setRogueTournWeeklyCur(?int $rogueTournWeeklyCur): self
    {
        $this->rogueTournWeeklyCur = $rogueTournWeeklyCur;
        return $this;
    }

    public function getRogueTournWeeklyMax(): ?int
    {
        return $this->rogueTournWeeklyMax;
    }

    public function setRogueTournWeeklyMax(?int $rogueTournWeeklyMax): self
    {
        $this->rogueTournWeeklyMax = $rogueTournWeeklyMax;
        return $this;
    }

    public function isRogueTournWeeklyUnlocked(): ?bool
    {
        return $this->rogueTournWeeklyUnlocked;
    }

    public function setRogueTournWeeklyUnlocked(?bool $rogueTournWeeklyUnlocked): self
    {
        $this->rogueTournWeeklyUnlocked = $rogueTournWeeklyUnlocked;
        return $this;
    }

    public function isRogueTournExpIsFull(): ?bool
    {
        return $this->rogueTournExpIsFull;
    }

    public function setRogueTournExpIsFull(?bool $rogueTournExpIsFull): self
    {
        $this->rogueTournExpIsFull = $rogueTournExpIsFull;
        return $this;
    }

    public function getGridFightWeeklyCur(): ?int
    {
        return $this->gridFightWeeklyCur;
    }

    public function setGridFightWeeklyCur(?int $gridFightWeeklyCur): self
    {
        $this->gridFightWeeklyCur = $gridFightWeeklyCur;
        return $this;
    }

    public function getGridFightWeeklyMax(): ?int
    {
        return $this->gridFightWeeklyMax;
    }

    public function setGridFightWeeklyMax(?int $gridFightWeeklyMax): self
    {
        $this->gridFightWeeklyMax = $gridFightWeeklyMax;
        return $this;
    }

    public function getPeriodScore(): ?int
    {
        return $this->periodScore;
    }

    public function setPeriodScore(?int $periodScore): self
    {
        $this->periodScore = $periodScore;
        return $this;
    }

    public function getPeriodMaxScore(): ?int
    {
        return $this->periodMaxScore;
    }

    public function setPeriodMaxScore(?int $periodMaxScore): self
    {
        $this->periodMaxScore = $periodMaxScore;
        return $this;
    }

    public function getAcceptedExpeditionNum(): ?int
    {
        return $this->acceptedExpeditionNum;
    }

    public function setAcceptedExpeditionNum(?int $acceptedExpeditionNum): self
    {
        $this->acceptedExpeditionNum = $acceptedExpeditionNum;
        return $this;
    }

    public function getTotalExpeditionNum(): ?int
    {
        return $this->totalExpeditionNum;
    }

    public function setTotalExpeditionNum(?int $totalExpeditionNum): self
    {
        $this->totalExpeditionNum = $totalExpeditionNum;
        return $this;
    }

    /**
     * @return HonkaiStarRailExpedition[]|null
     */
    public function getExpeditions(): ?array
    {
        return $this->expeditions;
    }

    /**
     * @param HonkaiStarRailExpedition[]|null $expeditions
     */
    public function setExpeditions(?array $expeditions): self
    {
        $this->expeditions = $expeditions;
        return $this;
    }

    public function getCurrentTs(): ?int
    {
        return $this->currentTs;
    }

    public function setCurrentTs(?int $currentTs): self
    {
        $this->currentTs = $currentTs;
        return $this;
    }

    public function getStaminaData(): GameNotesStamina
    {
        return new GameNotesStamina()
            ->setCurrentStamina($this->getCurrentStamina())
            ->setMaxStamina($this->getMaxStamina())
            ->setStaminaRecoverTime($this->getStaminaRecoverTime());
    }

    public function getDailiesData(): GameNotesDailies
    {
        return new GameNotesDailies()
            ->setCurrentTask($this->getCurrentTrainScore())
            ->setMaxTask($this->getMaxTrainScore());
    }

    public function getWeekliesData(): GameNotesWeeklies
    {
        return new GameNotesWeeklies()
            ->addWeekly(
                "Weekly Bosses",
                $this->getWeeklyCocoonCnt(),
                $this->getWeeklyCocoonLimit(),
                checkType: GameNotesWeeklyCheckType::CURRENT_EQUALS_ZERO
            )
            ->addWeekly(
                "Weekly Points",
                $this->getPeriodScore(),
                $this->getPeriodMaxScore(),
            );
    }

    public function getExpeditionsData(): GameNotesExpeditions
    {
        $expeditions = new GameNotesExpeditions();
        foreach ($this->getExpeditions() as $expedition) {
            $expeditions->addExpedition(
                $expedition->getAvatars(),
                strtolower($expedition->getStatus() ?? ""),
                $expedition->getRemainingTime()
            );
        }
        return $expeditions;
    }
}