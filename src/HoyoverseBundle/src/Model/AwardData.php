<?php

namespace App\HoyoverseBundle\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

class AwardData
{
    #[SerializedName('icon')]
    private string $icon;

    #[SerializedName('name')]
    private string $name;

    #[SerializedName('cnt')]
    private int $cnt;

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): AwardData
    {
        $this->icon = $icon;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): AwardData
    {
        $this->name = $name;
        return $this;
    }

    public function getCnt(): int
    {
        return $this->cnt;
    }

    public function setCnt(int $cnt): AwardData
    {
        $this->cnt = $cnt;
        return $this;
    }
}