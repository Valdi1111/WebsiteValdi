<?php

namespace App\HoyoverseBundle\Model;

class CheckInResult
{
    private bool $alreadySignedIn = false;

    private ?int $totalSignDay = null;

    private ?string $result = null;

    private ?AwardData $awardData = null;

    public function isAlreadySignedIn(): bool
    {
        return $this->alreadySignedIn;
    }

    public function setAlreadySignedIn(bool $alreadySignedIn): CheckInResult
    {
        $this->alreadySignedIn = $alreadySignedIn;
        return $this;
    }

    public function getTotalSignDay(): ?int
    {
        return $this->totalSignDay;
    }

    public function setTotalSignDay(?int $totalSignDay): CheckInResult
    {
        $this->totalSignDay = $totalSignDay;
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): CheckInResult
    {
        $this->result = $result;
        return $this;
    }

    public function getAwardData(): ?AwardData
    {
        return $this->awardData;
    }

    public function setAwardData(?AwardData $awardData): CheckInResult
    {
        $this->awardData = $awardData;
        return $this;
    }
}