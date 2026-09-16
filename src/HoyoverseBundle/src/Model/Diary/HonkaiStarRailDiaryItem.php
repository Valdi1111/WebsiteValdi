<?php

namespace App\HoyoverseBundle\Model\Diary;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Map(target: HoyoverseDiaryEntry::class)]
class HonkaiStarRailDiaryItem
{
    #[Map(target: 'actionKey')]
    #[SerializedName('action')]
    private ?string $action = null;

    #[Map(target: 'actionName')]
    #[SerializedName('action_name')]
    private ?string $actionName = null;

    #[Map(target: 'recordedAt')]
    #[SerializedName('time')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeImmutable $time = null;

    #[Map(target: 'amount')]
    #[SerializedName('num')]
    private ?int $num = null;

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): HonkaiStarRailDiaryItem
    {
        $this->action = $action;
        return $this;
    }

    public function getActionName(): ?string
    {
        return $this->actionName;
    }

    public function setActionName(?string $actionName): HonkaiStarRailDiaryItem
    {
        $this->actionName = $actionName;
        return $this;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function setTime(?\DateTimeImmutable $time): HonkaiStarRailDiaryItem
    {
        $this->time = $time;
        return $this;
    }

    public function getNum(): ?int
    {
        return $this->num;
    }

    public function setNum(?int $num): HonkaiStarRailDiaryItem
    {
        $this->num = $num;
        return $this;
    }

}