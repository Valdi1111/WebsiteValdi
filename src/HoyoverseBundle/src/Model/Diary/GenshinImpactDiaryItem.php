<?php

namespace App\HoyoverseBundle\Model\Diary;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Map(target: HoyoverseDiaryEntry::class)]
class GenshinImpactDiaryItem
{
    #[Map(target: 'actionKey')]
    #[SerializedName('action_id')]
    private ?int $actionId = null;

    #[Map(target: 'actionName')]
    #[SerializedName('action')]
    private ?string $action = null;

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

    public function getActionId(): ?int
    {
        return $this->actionId;
    }

    public function setActionId(?int $actionId): GenshinImpactDiaryItem
    {
        $this->actionId = $actionId;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): GenshinImpactDiaryItem
    {
        $this->action = $action;
        return $this;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function setTime(?\DateTimeImmutable $time): GenshinImpactDiaryItem
    {
        $this->time = $time;
        return $this;
    }

    public function getNum(): ?int
    {
        return $this->num;
    }

    public function setNum(?int $num): GenshinImpactDiaryItem
    {
        $this->num = $num;
        return $this;
    }

}