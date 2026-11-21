<?php

namespace App\PerfectworldBundle\Entity;

use App\PerfectworldBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One gacha history record imported from an export file.
 *
 * Identity: (profile, banner, pulledAt, groupPosition). The exporter uid changes between exporter
 * versions, so it is kept only as a reference.
 */
#[ORM\UniqueConstraint(name: 'IDX_perfectworld_pull_identity', columns: ['perfectworld_game_profile_id', 'banner_key', 'pulled_at', 'group_position'])]
#[ORM\Index(name: 'FK_perfectworld_pull_game_profile', columns: ['perfectworld_game_profile_id'])]
#[ORM\Index(name: 'IDX_perfectworld_pull_profile_banner_pulled_at', columns: ['perfectworld_game_profile_id', 'banner_key', 'pulled_at'])]
#[ORM\Index(name: 'IDX_perfectworld_pull_profile_rarity', columns: ['perfectworld_game_profile_id', 'rarity'])]
#[ORM\Table(name: 'perfectworld_pull')]
#[ORM\Entity(repositoryClass: PerfectworldPullRepository::class)]
class PerfectworldPull
{
    public const string KIND_ROLL = 'roll';
    public const string KIND_POINTS_GIFT = 'points_gift';
    public const string KIND_SLUMBERLAND = 'slumberland';
    public const string KIND_DRAW = 'draw';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PerfectworldGameProfile::class, inversedBy: 'pulls')]
    #[ORM\JoinColumn(name: 'perfectworld_game_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PerfectworldGameProfile $gameProfile = null;

    #[ORM\Column(length: 64)]
    private ?string $bannerKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $poolId = null;

    /**
     * Game display time, stored exactly as exported (see WallClockDateTimeImmutableType).
     */
    #[ORM\Column(type: WallClockDateTimeImmutableType::NAME)]
    private ?\DateTimeImmutable $pulledAt = null;

    /**
     * Index among the records sharing the same second, 0 = most recent.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $groupPosition = null;

    #[ORM\Column(length: 16)]
    private ?string $kind = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rollResult = null;

    #[ORM\Column(length: 16)]
    private ?string $rewardType = null;

    #[ORM\Column(length: 64)]
    private ?string $rewardId = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rarity = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $quantity = 1;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $exportUid = null;

    #[ORM\Column(length: 16)]
    private ?string $exporterVersion = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameProfile(): ?PerfectworldGameProfile
    {
        return $this->gameProfile;
    }

    public function setGameProfile(?PerfectworldGameProfile $gameProfile): static
    {
        $this->gameProfile = $gameProfile;

        return $this;
    }

    public function getBannerKey(): ?string
    {
        return $this->bannerKey;
    }

    public function setBannerKey(string $bannerKey): static
    {
        $this->bannerKey = $bannerKey;

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

    public function getPulledAt(): ?\DateTimeImmutable
    {
        return $this->pulledAt;
    }

    public function setPulledAt(\DateTimeImmutable $pulledAt): static
    {
        $this->pulledAt = $pulledAt;

        return $this;
    }

    public function getGroupPosition(): ?int
    {
        return $this->groupPosition;
    }

    public function setGroupPosition(int $groupPosition): static
    {
        $this->groupPosition = $groupPosition;

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getRollResult(): ?int
    {
        return $this->rollResult;
    }

    public function setRollResult(?int $rollResult): static
    {
        $this->rollResult = $rollResult;

        return $this;
    }

    public function getRewardType(): ?string
    {
        return $this->rewardType;
    }

    public function setRewardType(string $rewardType): static
    {
        $this->rewardType = $rewardType;

        return $this;
    }

    public function getRewardId(): ?string
    {
        return $this->rewardId;
    }

    public function setRewardId(string $rewardId): static
    {
        $this->rewardId = $rewardId;

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

    public function setRarity(?int $rarity): static
    {
        $this->rarity = $rarity;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getExportUid(): ?string
    {
        return $this->exportUid;
    }

    public function setExportUid(?string $exportUid): static
    {
        $this->exportUid = $exportUid;

        return $this;
    }

    public function getExporterVersion(): ?string
    {
        return $this->exporterVersion;
    }

    public function setExporterVersion(string $exporterVersion): static
    {
        $this->exporterVersion = $exporterVersion;

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
