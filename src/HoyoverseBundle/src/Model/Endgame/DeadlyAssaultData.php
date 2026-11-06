<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

class DeadlyAssaultData
{
    #[SerializedPath('[hadal_info_v2][zone_id]')]
    private ?int $zoneId = null;

    #[SerializedPath('[hadal_info_v2][pass_fifth_floor]')]
    private ?bool $passFifthFloor = null;

    #[SerializedPath('[hadal_info_v2][brief][cur_period_zone_layer_count]')]
    private ?int $zoneLayerCount = null;

    #[SerializedPath('[hadal_info_v2][brief][score]')]
    private ?int $score = null;

    #[SerializedPath('[hadal_info_v2][brief][rank_percent]')]
    private ?int $rankPercent = null;

    #[SerializedPath('[hadal_info_v2][brief][rating]')]
    private ?string $rating = null;

    #[SerializedPath('[hadal_info_v2][brief][max_score]')]
    private ?int $maxScore = null;

    #[SerializedPath('[hadal_info_v2][begin_time]')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'U',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeInterface $startTime = null;

    #[SerializedPath('[hadal_info_v2][end_time]')]
    #[Context([
        DateTimeNormalizer::FORMAT_KEY => 'U',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ])]
    private ?\DateTimeInterface $endTime = null;

    public function getZoneId(): ?int
    {
        return $this->zoneId;
    }

    public function setZoneId(?int $zoneId): static
    {
        $this->zoneId = $zoneId;
        return $this;
    }

    public function getPassFifthFloor(): ?bool
    {
        return $this->passFifthFloor;
    }

    public function setPassFifthFloor(?bool $passFifthFloor): static
    {
        $this->passFifthFloor = $passFifthFloor;
        return $this;
    }

    public function getZoneLayerCount(): ?int
    {
        return $this->zoneLayerCount;
    }

    public function setZoneLayerCount(?int $zoneLayerCount): static
    {
        $this->zoneLayerCount = $zoneLayerCount;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getRankPercent(): ?int
    {
        return $this->rankPercent;
    }

    public function setRankPercent(?int $rankPercent): static
    {
        $this->rankPercent = $rankPercent;
        return $this;
    }

    public function getRating(): ?string
    {
        return $this->rating;
    }

    public function setRating(?string $rating): static
    {
        $this->rating = $rating;
        return $this;
    }

    public function getMaxScore(): ?int
    {
        return $this->maxScore;
    }

    public function setMaxScore(?int $maxScore): static
    {
        $this->maxScore = $maxScore;
        return $this;
    }

    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeInterface $startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeInterface $endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }
}
