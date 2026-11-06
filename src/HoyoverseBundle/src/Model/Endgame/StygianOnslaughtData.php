<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class StygianOnslaughtData
{
    /**
     * @var StygianOnslaughtType[]|null
     */
    #[SerializedName('data')]
    private ?array $data = null;

    #[SerializedName('is_unlock')]
    private ?bool $isUnlock = null;

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getIsUnlock(): ?bool
    {
        return $this->isUnlock;
    }

    public function setIsUnlock(?bool $isUnlock): static
    {
        $this->isUnlock = $isUnlock;
        return $this;
    }
}
