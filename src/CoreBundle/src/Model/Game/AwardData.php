<?php

namespace App\CoreBundle\Model\Game;

use Symfony\Component\Serializer\Attribute\SerializedName;

class AwardData
{
    #[SerializedName('id')]
    private string $id = '';

    #[SerializedName('name')]
    private string $name = '';

    #[SerializedName('cnt')]
    private int $cnt = 1;

    #[SerializedName('icon')]
    private ?string $icon = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCnt(): int
    {
        return $this->cnt;
    }

    public function setCnt(int $cnt): self
    {
        $this->cnt = $cnt;
        return $this;
    }

    public function getCount(): int
    {
        return $this->getCnt();
    }

    public function setCount(int $count): self
    {
        return $this->setCnt($count);
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }
}
