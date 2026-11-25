<?php

namespace App\HoyoverseBundle\Model;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Check-in info of HoYoLAB. Only the sign state (is_sign, total_sign_day) is required, the check-in reads nothing
 * else: the other fields are null when HoYoLAB leaves them out or sends them empty or malformed.
 */
class SignInfo
{
    #[SerializedName('total_sign_day')]
    private int $totalSignDay;

    // "2026-11-22": the explicit format avoids the loose parsing Symfony 8.1 deprecates; "!" resets the time to midnight
    #[SerializedName('today')]
    #[Context(denormalizationContext: [DateTimeNormalizer::FORMAT_KEY => '!Y-m-d'])]
    private ?\DateTime $today = null;

    #[SerializedName('is_sign')]
    private bool $isSign;

    #[SerializedName('first_bind')]
    private ?bool $firstBind = null;

    #[SerializedName('is_sub')]
    private ?bool $isSub = null;

    #[SerializedName('region')]
    private ?string $region = null;

    #[SerializedName('month_last_day')]
    private ?bool $monthLastDay = null;

    /**
     * Whether both is_sign and total_sign_day were read: without them nothing tells whether today is claimed.
     */
    public function hasSignState(): bool
    {
        return isset($this->isSign, $this->totalSignDay);
    }

    public function getTotalSignDay(): int
    {
        return $this->totalSignDay;
    }

    public function setTotalSignDay(int $totalSignDay): SignInfo
    {
        $this->totalSignDay = $totalSignDay;
        return $this;
    }

    public function getToday(): ?\DateTime
    {
        return $this->today;
    }

    public function setToday(?\DateTime $today): SignInfo
    {
        $this->today = $today;
        return $this;
    }

    public function isSign(): bool
    {
        return $this->isSign;
    }

    public function setIsSign(bool $isSign): SignInfo
    {
        $this->isSign = $isSign;
        return $this;
    }

    public function isFirstBind(): ?bool
    {
        return $this->firstBind;
    }

    public function setFirstBind(?bool $firstBind): SignInfo
    {
        $this->firstBind = $firstBind;
        return $this;
    }

    public function isSub(): ?bool
    {
        return $this->isSub;
    }

    public function setIsSub(?bool $isSub): SignInfo
    {
        $this->isSub = $isSub;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): SignInfo
    {
        $this->region = $region;
        return $this;
    }

    public function isMonthLastDay(): ?bool
    {
        return $this->monthLastDay;
    }

    public function setMonthLastDay(?bool $monthLastDay): SignInfo
    {
        $this->monthLastDay = $monthLastDay;
        return $this;
    }
}