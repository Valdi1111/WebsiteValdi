<?php

namespace App\CoreBundle\Model\Game;

class CheckInResult
{
    private string $status = 'unknown';

    private bool $alreadySignedIn = false;

    private ?int $totalSignDay = null;

    private ?string $result = null;

    private ?AwardData $awardData = null;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isAlreadySignedIn(): bool
    {
        return $this->alreadySignedIn;
    }

    public function setAlreadySignedIn(bool $alreadySignedIn): self
    {
        $this->alreadySignedIn = $alreadySignedIn;
        return $this;
    }

    public function getTotalSignDay(): ?int
    {
        return $this->totalSignDay;
    }

    public function setTotalSignDay(?int $totalSignDay): self
    {
        $this->totalSignDay = $totalSignDay;
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function getAwardData(): ?AwardData
    {
        return $this->awardData;
    }

    public function setAwardData(?AwardData $awardData): self
    {
        $this->awardData = $awardData;
        return $this;
    }
}
