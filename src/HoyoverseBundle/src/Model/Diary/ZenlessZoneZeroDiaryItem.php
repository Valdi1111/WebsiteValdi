<?php

namespace App\HoyoverseBundle\Model\Diary;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use function Symfony\Component\String\u;

#[Map(target: HoyoverseDiaryEntry::class)]
class ZenlessZoneZeroDiaryItem
{
    #[SerializedName('id')]
    private ?string $id = null;

    #[Map(target: 'actionKey')]
    #[Map(target: 'actionName', transform: [self::class, 'transformActionName'])]
    #[SerializedName('action')]
    private ?string $action = null;

    #[Map(target: 'recordedAt')]
    #[SerializedName('time')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'U',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeImmutable $time = null;

    #[Map(target: 'amount')]
    #[SerializedName('num')]
    private ?int $num = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): ZenlessZoneZeroDiaryItem
    {
        $this->id = $id;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): ZenlessZoneZeroDiaryItem
    {
        $this->action = $action;
        return $this;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function setTime(?\DateTimeImmutable $time): ZenlessZoneZeroDiaryItem
    {
        $this->time = $time;
        return $this;
    }

    public function getNum(): ?int
    {
        return $this->num;
    }

    public function setNum(?int $num): ZenlessZoneZeroDiaryItem
    {
        $this->num = $num;
        return $this;
    }

    public static function transformActionName(?string $value, object $source): ?string
    {
        if ($value === null) {
            return null;
        }
        return u($value)
            ->replace('_', ' ')
            ->title(true)
            ->toString();
    }

}