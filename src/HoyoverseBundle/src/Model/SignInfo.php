<?php

namespace App\HoyoverseBundle\Model;

class SignInfo
{
    private int $totalSignDay;

    private \DateTime $today;

    private bool $isSign;

    private bool $firstBind;

    private bool $isSub;

    private string $region;

    private bool $monthLastDay;

    public function getTotalSignDay(): int
    {
        return $this->totalSignDay;
    }

    public function setTotalSignDay(int $totalSignDay): SignInfo
    {
        $this->totalSignDay = $totalSignDay;
        return $this;
    }

    public function getToday(): \DateTime
    {
        return $this->today;
    }

    public function setToday(\DateTime $today): SignInfo
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

    public function isFirstBind(): bool
    {
        return $this->firstBind;
    }

    public function setFirstBind(bool $firstBind): SignInfo
    {
        $this->firstBind = $firstBind;
        return $this;
    }

    public function isSub(): bool
    {
        return $this->isSub;
    }

    public function setIsSub(bool $isSub): SignInfo
    {
        $this->isSub = $isSub;
        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): SignInfo
    {
        $this->region = $region;
        return $this;
    }

    public function isMonthLastDay(): bool
    {
        return $this->monthLastDay;
    }

    public function setMonthLastDay(bool $monthLastDay): SignInfo
    {
        $this->monthLastDay = $monthLastDay;
        return $this;
    }
}