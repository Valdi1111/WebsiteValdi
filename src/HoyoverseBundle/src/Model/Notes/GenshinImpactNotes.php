<?php

namespace App\HoyoverseBundle\Model\Notes;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

class GenshinImpactNotes implements GameNotes
{
    // =========================================================================
    // Stamina (Resin)
    // =========================================================================

    #[SerializedName('current_resin')]
    private ?int $currentResin = null;

    #[SerializedName('max_resin')]
    private ?int $maxResin = null;

    #[SerializedName('resin_recovery_time')]
    private ?string $resinRecoveryTime = null;

    // =========================================================================
    // Dailies (Task & Attendance)
    // =========================================================================

    #[SerializedName('finished_task_num')]
    private ?int $finishedTaskNum = null;

    #[SerializedName('total_task_num')]
    private ?int $totalTaskNum = null;

    #[SerializedName('is_extra_task_reward_received')]
    private ?bool $isExtraTaskRewardReceived = null;

    #[SerializedPath('[daily_task?][stored_attendance]')]
    private ?string $storedAttendance = null;

    #[SerializedPath('[daily_task?][stored_attendance_refresh_countdown]')]
    private ?int $storedAttendanceRefreshCountdown = null;

    // =========================================================================
    // Weeklies (Resin Discount)
    // =========================================================================

    #[SerializedName('remain_resin_discount_num')]
    private ?int $remainResinDiscountNum = null;

    #[SerializedName('resin_discount_num_limit')]
    private ?int $resinDiscountNumLimit = null;

    // =========================================================================
    // Realm (Serenitea Pot)
    // =========================================================================

    #[SerializedName('current_home_coin')]
    private ?int $currentHomeCoin = null;

    #[SerializedName('max_home_coin')]
    private ?int $maxHomeCoin = null;

    #[SerializedName('home_coin_recovery_time')]
    private ?string $homeCoinRecoveryTime = null;

    // =========================================================================
    // Expeditions
    // =========================================================================

    #[SerializedName('current_expedition_num')]
    private ?int $currentExpeditionNum = null;

    #[SerializedName('max_expedition_num')]
    private ?int $maxExpeditionNum = null;

    /**
     * @var GenshinImpactExpedition[]|null
     */
    #[SerializedName('expeditions')]
    private ?array $expeditions = null;

    // =========================================================================
    // Parametric Transformer
    // =========================================================================

    #[SerializedPath('[transformer?][obtained]')]
    private ?bool $transformerObtained = null;

    #[SerializedPath('[transformer?][recovery_time?][reached]')]
    private ?bool $transformerReached = null;

    // =========================================================================
    // Getters & Setters
    // =========================================================================

    public function getCurrentResin(): ?int
    {
        return $this->currentResin;
    }

    public function setCurrentResin(?int $currentResin): self
    {
        $this->currentResin = $currentResin;
        return $this;
    }

    public function getMaxResin(): ?int
    {
        return $this->maxResin;
    }

    public function setMaxResin(?int $maxResin): self
    {
        $this->maxResin = $maxResin;
        return $this;
    }

    public function getResinRecoveryTime(): ?string
    {
        return $this->resinRecoveryTime;
    }

    public function setResinRecoveryTime(?string $resinRecoveryTime): self
    {
        $this->resinRecoveryTime = $resinRecoveryTime;
        return $this;
    }

    public function getFinishedTaskNum(): ?int
    {
        return $this->finishedTaskNum;
    }

    public function setFinishedTaskNum(?int $finishedTaskNum): self
    {
        $this->finishedTaskNum = $finishedTaskNum;
        return $this;
    }

    public function getTotalTaskNum(): ?int
    {
        return $this->totalTaskNum;
    }

    public function setTotalTaskNum(?int $totalTaskNum): self
    {
        $this->totalTaskNum = $totalTaskNum;
        return $this;
    }

    public function isExtraTaskRewardReceived(): ?bool
    {
        return $this->isExtraTaskRewardReceived;
    }

    public function setIsExtraTaskRewardReceived(?bool $isExtraTaskRewardReceived): self
    {
        $this->isExtraTaskRewardReceived = $isExtraTaskRewardReceived;
        return $this;
    }

    public function getStoredAttendance(): ?string
    {
        return $this->storedAttendance;
    }

    public function setStoredAttendance(?string $storedAttendance): self
    {
        $this->storedAttendance = $storedAttendance;
        return $this;
    }

    public function getStoredAttendanceRefreshCountdown(): ?int
    {
        return $this->storedAttendanceRefreshCountdown;
    }

    public function setStoredAttendanceRefreshCountdown(?int $storedAttendanceRefreshCountdown): self
    {
        $this->storedAttendanceRefreshCountdown = $storedAttendanceRefreshCountdown;
        return $this;
    }

    public function getRemainResinDiscountNum(): ?int
    {
        return $this->remainResinDiscountNum;
    }

    public function setRemainResinDiscountNum(?int $remainResinDiscountNum): self
    {
        $this->remainResinDiscountNum = $remainResinDiscountNum;
        return $this;
    }

    public function getResinDiscountNumLimit(): ?int
    {
        return $this->resinDiscountNumLimit;
    }

    public function setResinDiscountNumLimit(?int $resinDiscountNumLimit): self
    {
        $this->resinDiscountNumLimit = $resinDiscountNumLimit;
        return $this;
    }

    public function getCurrentHomeCoin(): ?int
    {
        return $this->currentHomeCoin;
    }

    public function setCurrentHomeCoin(?int $currentHomeCoin): self
    {
        $this->currentHomeCoin = $currentHomeCoin;
        return $this;
    }

    public function getMaxHomeCoin(): ?int
    {
        return $this->maxHomeCoin;
    }

    public function setMaxHomeCoin(?int $maxHomeCoin): self
    {
        $this->maxHomeCoin = $maxHomeCoin;
        return $this;
    }

    public function getHomeCoinRecoveryTime(): ?string
    {
        return $this->homeCoinRecoveryTime;
    }

    public function setHomeCoinRecoveryTime(?string $homeCoinRecoveryTime): self
    {
        $this->homeCoinRecoveryTime = $homeCoinRecoveryTime;
        return $this;
    }

    public function getCurrentExpeditionNum(): ?int
    {
        return $this->currentExpeditionNum;
    }

    public function setCurrentExpeditionNum(?int $currentExpeditionNum): self
    {
        $this->currentExpeditionNum = $currentExpeditionNum;
        return $this;
    }

    public function getMaxExpeditionNum(): ?int
    {
        return $this->maxExpeditionNum;
    }

    public function setMaxExpeditionNum(?int $maxExpeditionNum): self
    {
        $this->maxExpeditionNum = $maxExpeditionNum;
        return $this;
    }

    /**
     * @return GenshinImpactExpedition[]|null
     */
    public function getExpeditions(): ?array
    {
        return $this->expeditions;
    }

    /**
     * @param GenshinImpactExpedition[]|null $expeditions
     */
    public function setExpeditions(?array $expeditions): self
    {
        $this->expeditions = $expeditions;
        return $this;
    }

    public function getTransformerObtained(): ?bool
    {
        return $this->transformerObtained;
    }

    public function setTransformerObtained(?bool $transformerObtained): self
    {
        $this->transformerObtained = $transformerObtained;
        return $this;
    }

    public function getTransformerReached(): ?bool
    {
        return $this->transformerReached;
    }

    public function setTransformerReached(?bool $transformerReached): self
    {
        $this->transformerReached = $transformerReached;
        return $this;
    }
}