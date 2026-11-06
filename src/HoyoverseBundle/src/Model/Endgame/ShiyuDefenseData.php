<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

class ShiyuDefenseData
{
    /**
     * @var ShiyuDefenseItem[]|null
     */
    #[SerializedName('list')]
    private ?array $list = null;

    #[SerializedName('start_time')]
    private ?\DateTimeInterface $startTime = null;

    #[SerializedName('end_time')]
    private ?\DateTimeInterface $endTime = null;

    #[SerializedName('total_max_score')]
    private ?int $totalMaxScore = null;

    #[SerializedName('room_max_score')]
    private ?int $roomMaxScore = null;

    public function getList(): ?array
    {
        return $this->list;
    }

    public function setList(?array $list): static
    {
        $this->list = $list;
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

    public function getTotalMaxScore(): ?int
    {
        return $this->totalMaxScore;
    }

    public function setTotalMaxScore(?int $totalMaxScore): static
    {
        $this->totalMaxScore = $totalMaxScore;
        return $this;
    }

    public function getRoomMaxScore(): ?int
    {
        return $this->roomMaxScore;
    }

    public function setRoomMaxScore(?int $roomMaxScore): static
    {
        $this->roomMaxScore = $roomMaxScore;
        return $this;
    }

}
