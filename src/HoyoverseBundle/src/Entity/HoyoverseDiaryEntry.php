<?php

namespace App\HoyoverseBundle\Entity;

use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Repository\HoyoverseDiaryEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'IDX_hoyoverse_game_profile_id_period_currency', columns: ['hoyoverse_game_profile_id', 'period', 'currency'])]
#[ORM\Table(name: 'hoyoverse_diary_entry')]
#[ORM\Entity(repositoryClass: HoyoverseDiaryEntryRepository::class)]
class HoyoverseDiaryEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HoyoverseGameProfile::class, inversedBy: 'diaryEntries')]
    #[ORM\JoinColumn(name: 'hoyoverse_game_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?HoyoverseGameProfile $gameProfile;

    #[ORM\Column(length: 7)]
    private ?string $period;

    #[ORM\Column(length: 50, enumType: GameDiaryCurrency::class)]
    private ?GameDiaryCurrency $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeInterface $recordedAt = null;

    #[ORM\Column]
    private ?int $amount;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $actionKey = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $actionName = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameProfile(): ?HoyoverseGameProfile
    {
        return $this->gameProfile;
    }

    public function setGameProfile(?HoyoverseGameProfile $gameProfile): static
    {
        $this->gameProfile = $gameProfile;

        return $this;
    }

    public function getPeriod(): ?string
    {
        return $this->period;
    }

    public function setPeriod(?string $period): HoyoverseDiaryEntry
    {
        $this->period = $period;
        return $this;
    }

    public function getCurrency(): ?GameDiaryCurrency
    {
        return $this->currency;
    }

    public function setCurrency(?GameDiaryCurrency $currency): HoyoverseDiaryEntry
    {
        $this->currency = $currency;
        return $this;
    }

    public function getRecordedAt(): ?\DateTimeInterface
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(?\DateTimeInterface $recordedAt): HoyoverseDiaryEntry
    {
        $this->recordedAt = $recordedAt;
        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): HoyoverseDiaryEntry
    {
        $this->amount = $amount;
        return $this;
    }

    public function getActionKey(): ?string
    {
        return $this->actionKey;
    }

    public function setActionKey(?string $actionKey): HoyoverseDiaryEntry
    {
        $this->actionKey = $actionKey;
        return $this;
    }

    public function getActionName(): ?string
    {
        return $this->actionName;
    }

    public function setActionName(?string $actionName): HoyoverseDiaryEntry
    {
        $this->actionName = $actionName;
        return $this;
    }
}