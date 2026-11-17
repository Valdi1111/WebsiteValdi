<?php

namespace App\GryphlineBundle\Entity;

use App\GryphlineBundle\Repository\GryphlinePullRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'gryphline_pull')]
#[ORM\UniqueConstraint(name: 'IDX_gryphline_game_profile_id_pool_category_seq_id', columns: ['gryphline_game_profile_id', 'pool_category', 'seq_id'])]
#[ORM\Index(name: 'FK_gryphline_pull_gryphline_game_profile', columns: ['gryphline_game_profile_id'])]
#[ORM\Index(name: 'IDX_gryphline_game_profile_id_pool_type', columns: ['gryphline_game_profile_id', 'pool_type'])]
#[ORM\Index(name: 'IDX_gryphline_game_profile_id_rarity', columns: ['gryphline_game_profile_id', 'rarity'])]
#[ORM\Index(name: 'IDX_gryphline_game_profile_id_pulled_at', columns: ['gryphline_game_profile_id', 'pulled_at'])]
#[ORM\Entity(repositoryClass: GryphlinePullRepository::class)]
class GryphlinePull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GryphlineGameProfile::class, inversedBy: 'pulls')]
    #[ORM\JoinColumn(name: 'gryphline_game_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?GryphlineGameProfile $gameProfile = null;

    #[ORM\Column(length: 64)]
    private ?string $seqId = null;

    #[ORM\Column(length: 64)]
    private ?string $poolType = null;

    #[ORM\Column(length: 32)]
    private ?string $poolCategory = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $poolId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $poolName = null;

    #[ORM\Column(length: 64)]
    private ?string $itemId = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $rarity = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $pulledAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $gachaTs = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameProfile(): ?GryphlineGameProfile
    {
        return $this->gameProfile;
    }

    public function setGameProfile(?GryphlineGameProfile $gameProfile): static
    {
        $this->gameProfile = $gameProfile;

        return $this;
    }

    public function getSeqId(): ?string
    {
        return $this->seqId;
    }

    public function setSeqId(string $seqId): static
    {
        $this->seqId = $seqId;

        return $this;
    }

    public function getPoolType(): ?string
    {
        return $this->poolType;
    }

    public function setPoolType(?string $poolType): static
    {
        $this->poolType = $poolType;

        return $this;
    }

    public function getPoolCategory(): ?string
    {
        return $this->poolCategory;
    }

    public function setPoolCategory(?string $poolCategory): static
    {
        $this->poolCategory = $poolCategory;

        return $this;
    }

    public function getPoolId(): ?string
    {
        return $this->poolId;
    }

    public function setPoolId(?string $poolId): static
    {
        $this->poolId = $poolId;

        return $this;
    }

    public function getPoolName(): ?string
    {
        return $this->poolName;
    }

    public function setPoolName(?string $poolName): static
    {
        $this->poolName = $poolName;

        return $this;
    }

    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    public function setItemId(string $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRarity(): ?int
    {
        return $this->rarity;
    }

    public function setRarity(int $rarity): static
    {
        $this->rarity = $rarity;

        return $this;
    }

    public function getPulledAt(): ?\DateTimeImmutable
    {
        return $this->pulledAt;
    }

    public function setPulledAt(\DateTimeImmutable $pulledAt): static
    {
        $this->pulledAt = $pulledAt;

        return $this;
    }

    public function getGachaTs(): ?string
    {
        return $this->gachaTs;
    }

    public function setGachaTs(?string $gachaTs): static
    {
        $this->gachaTs = $gachaTs;

        return $this;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): static
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }
}
