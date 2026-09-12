<?php

namespace App\HoyoverseBundle\Model\Notes;

use Symfony\Component\Serializer\Attribute\SerializedName;

class GenshinImpactExpedition
{
    #[SerializedName('avatar_side_icon')]
    private ?string $avatarSideIcon = null;

    #[SerializedName('status')]
    private ?string $status = null;

    #[SerializedName('remained_time')]
    private ?string $remainedTime = null;

    public function getAvatarSideIcon(): ?string
    {
        return $this->avatarSideIcon;
    }

    public function setAvatarSideIcon(?string $avatarSideIcon): self
    {
        $this->avatarSideIcon = $avatarSideIcon;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRemainedTime(): ?string
    {
        return $this->remainedTime;
    }

    public function setRemainedTime(?string $remainedTime): self
    {
        $this->remainedTime = $remainedTime;
        return $this;
    }
}