<?php

namespace App\HoyoverseBundle\Model\Notes;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

class ZenlessZoneZeroNotes implements GameNotes, HasStaminaNotes, HasDailiesNotes, HasWeekliesNotes, HasShopStatusNotes
{
    // =========================================================================
    // Stamina (Battery Charge / Energy)
    // =========================================================================

    #[SerializedPath('[energy?][progress?][current]')]
    private ?int $currentEnergy = null;

    #[SerializedPath('[energy?][progress?][max]')]
    private ?int $maxEnergy = null;

    #[SerializedPath('[energy?][restore]')]
    private ?int $energyRestore = null;

    // =========================================================================
    // Dailies (Engagement / Vitality)
    // =========================================================================

    #[SerializedPath('[vitality?][current]')]
    private ?int $currentVitality = null;

    #[SerializedPath('[vitality?][max]')]
    private ?int $maxVitality = null;

    #[SerializedName('card_sign')]
    private ?ZenlessZoneZeroCardSign $cardSign = null;

    // =========================================================================
    // Weeklies (Bounty Commission & Survey Points / Hollow Zero)
    // =========================================================================

    #[SerializedPath('[bounty_commission?][num]')]
    private ?int $bountyCommissionNum = null;

    #[SerializedPath('[bounty_commission?][total]')]
    private ?int $bountyCommissionTotal = null;

    #[SerializedPath('[bounty_commission?][refresh_time]')]
    private ?int $bountyCommissionRefreshTime = null;

    #[SerializedPath('[bounty_commission?][unlock]')]
    private ?bool $bountyCommissionUnlock = null;

    #[SerializedPath('[survey_points?][num]')]
    private ?int $surveyPointsNum = null;

    #[SerializedPath('[survey_points?][total]')]
    private ?int $surveyPointsTotal = null;

    #[SerializedPath('[weekly_task?][cur_point]')]
    private ?int $weeklyTaskCurPoint = null;

    #[SerializedPath('[weekly_task?][max_point]')]
    private ?int $weeklyTaskMaxPoint = null;

    #[SerializedPath('[weekly_task?][unlock]')]
    private ?bool $weeklyTaskUnlock = null;

    // =========================================================================
    // Random Play (VHS Store Sale) & Cafe
    // =========================================================================

    #[SerializedPath('[vhs_sale?][sale_state]')]
    private ?ZenlessZoneZeroVhsSale $vhsSaleState = null;

    #[SerializedName('cafe_state')]
    private ?ZenlessZoneZeroCafe $cafeState = null;

    // =========================================================================
    // Getters & Setters
    // =========================================================================

    public function getCurrentEnergy(): int
    {
        return $this->currentEnergy ?? 0;
    }

    public function setCurrentEnergy(?int $currentEnergy): self
    {
        $this->currentEnergy = $currentEnergy;
        return $this;
    }

    public function getMaxEnergy(): int
    {
        return $this->maxEnergy ?? 0;
    }

    public function setMaxEnergy(?int $maxEnergy): self
    {
        $this->maxEnergy = $maxEnergy;
        return $this;
    }

    public function getEnergyRestore(): int
    {
        return $this->energyRestore ?? 0;
    }

    public function setEnergyRestore(?int $energyRestore): self
    {
        $this->energyRestore = $energyRestore;
        return $this;
    }

    public function getCurrentVitality(): int
    {
        return $this->currentVitality ?? 0;
    }

    public function setCurrentVitality(?int $currentVitality): self
    {
        $this->currentVitality = $currentVitality;
        return $this;
    }

    public function getMaxVitality(): int
    {
        return $this->maxVitality ?? 0;
    }

    public function setMaxVitality(?int $maxVitality): self
    {
        $this->maxVitality = $maxVitality;
        return $this;
    }

    public function getCardSign(): ?ZenlessZoneZeroCardSign
    {
        return $this->cardSign;
    }

    public function setCardSign(?ZenlessZoneZeroCardSign $cardSign): self
    {
        $this->cardSign = $cardSign;
        return $this;
    }

    public function getBountyCommissionNum(): int
    {
        return $this->bountyCommissionNum ?? 0;
    }

    public function setBountyCommissionNum(?int $bountyCommissionNum): self
    {
        $this->bountyCommissionNum = $bountyCommissionNum;
        return $this;
    }

    public function getBountyCommissionTotal(): int
    {
        return $this->bountyCommissionTotal ?? 0;
    }

    public function setBountyCommissionTotal(?int $bountyCommissionTotal): self
    {
        $this->bountyCommissionTotal = $bountyCommissionTotal;
        return $this;
    }

    public function getBountyCommissionRefreshTime(): ?int
    {
        return $this->bountyCommissionRefreshTime;
    }

    public function setBountyCommissionRefreshTime(?int $bountyCommissionRefreshTime): self
    {
        $this->bountyCommissionRefreshTime = $bountyCommissionRefreshTime;
        return $this;
    }

    public function isBountyCommissionUnlock(): ?bool
    {
        return $this->bountyCommissionUnlock;
    }

    public function setBountyCommissionUnlock(?bool $bountyCommissionUnlock): self
    {
        $this->bountyCommissionUnlock = $bountyCommissionUnlock;
        return $this;
    }

    public function getSurveyPointsNum(): int
    {
        return $this->surveyPointsNum ?? 0;
    }

    public function setSurveyPointsNum(?int $surveyPointsNum): self
    {
        $this->surveyPointsNum = $surveyPointsNum;
        return $this;
    }

    public function getSurveyPointsTotal(): int
    {
        return $this->surveyPointsTotal ?? 0;
    }

    public function setSurveyPointsTotal(?int $surveyPointsTotal): self
    {
        $this->surveyPointsTotal = $surveyPointsTotal;
        return $this;
    }

    public function getWeeklyTaskCurPoint(): ?int
    {
        return $this->weeklyTaskCurPoint;
    }

    public function setWeeklyTaskCurPoint(?int $weeklyTaskCurPoint): self
    {
        $this->weeklyTaskCurPoint = $weeklyTaskCurPoint;
        return $this;
    }

    public function getWeeklyTaskMaxPoint(): ?int
    {
        return $this->weeklyTaskMaxPoint;
    }

    public function setWeeklyTaskMaxPoint(?int $weeklyTaskMaxPoint): self
    {
        $this->weeklyTaskMaxPoint = $weeklyTaskMaxPoint;
        return $this;
    }

    public function isWeeklyTaskUnlock(): ?bool
    {
        return $this->weeklyTaskUnlock;
    }

    public function setWeeklyTaskUnlock(?bool $weeklyTaskUnlock): self
    {
        $this->weeklyTaskUnlock = $weeklyTaskUnlock;
        return $this;
    }

    public function getVhsSaleState(): ?ZenlessZoneZeroVhsSale
    {
        return $this->vhsSaleState;
    }

    public function setVhsSaleState(?ZenlessZoneZeroVhsSale $vhsSaleState): self
    {
        $this->vhsSaleState = $vhsSaleState;
        return $this;
    }

    public function getCafeState(): ?ZenlessZoneZeroCafe
    {
        return $this->cafeState;
    }

    public function setCafeState(?ZenlessZoneZeroCafe $cafeState): self
    {
        $this->cafeState = $cafeState;
        return $this;
    }

    public function getStaminaData(): GameNotesStamina
    {
        return new GameNotesStamina()
            ->setCurrentStamina($this->getCurrentEnergy())
            ->setMaxStamina($this->getMaxEnergy())
            ->setStaminaRecoverTime($this->getEnergyRestore());
    }

    public function getDailiesData(): GameNotesDailies
    {
        return new GameNotesDailies()
            ->addDaily(
                new GameNotesProgressMetric()
                    ->setName("Engagement Points")
                    ->setCurrentValue($this->getCurrentVitality())
                    ->setMaxValue($this->getMaxVitality())
            )
            ->addDaily(
                new GameNotesStateMetric()
                    ->setName("Scratch Card")
                    ->setCurrentValue($this->getCardSign())
                    ->setTargetValue(ZenlessZoneZeroCardSign::DONE)
            )
            ->addDaily(
                new GameNotesStateMetric()
                    ->setName("Coff Cafe")
                    ->setCurrentValue($this->getCafeState())
                    ->setTargetValue(ZenlessZoneZeroCafe::DONE)
            );
    }

    public function getWeekliesData(): GameNotesWeeklies
    {
        return new GameNotesWeeklies()
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Bounty Commissions")
                    ->setCurrentValue($this->getBountyCommissionNum())
                    ->setMaxValue($this->getBountyCommissionTotal())
                    ->setUnlocked($this->isBountyCommissionUnlock())
            )
            ->addWeekly(
                new GameNotesProgressMetric()
                    ->setName("Ridu Weekly Points")
                    ->setCurrentValue($this->getWeeklyTaskCurPoint())
                    ->setMaxValue($this->getWeeklyTaskMaxPoint())
                    ->setUnlocked($this->isWeeklyTaskUnlock())
            );
    }
}