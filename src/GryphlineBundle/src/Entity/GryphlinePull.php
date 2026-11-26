<?php

namespace App\GryphlineBundle\Entity;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Pull\PullOrigin;
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
    /** Kind of a headhunting record: a pull; every other kind is a bonus entry (gift_intel_book, gift_weapon, ...) */
    public const string KIND_DRAW = 'draw';

    /** Labels of the bonus entries, as the official record page names them */
    public const array BONUS_LABELS = [
        'gift_intel_book' => 'Headhunting Dossier',
        'gift_operator_token' => 'Token Tribute',
        'gift_weapon_box' => 'Arms Offering',
        'gift_weapon' => 'Tribute',
        'gift' => 'Tribute',
    ];

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

    /** Null for filler rows and for manual S pulls typed as free text */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $itemId = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    /** Null for bonus entries without a rarity (e.g. the Headhunting Dossier) */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rarity = null;

    /**
     * UTC wall-clock time of gachaTs, read back as UTC so DST gaps of the default timezone never shift it.
     */
    #[ORM\Column(type: WallClockDateTimeImmutableType::NAME)]
    private ?\DateTimeImmutable $pulledAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $gachaTs = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(length: 16, enumType: PullOrigin::class, options: ['default' => 'recorded'])]
    private PullOrigin $origin = PullOrigin::Recorded;

    /** Real pity declared by the user (S rows only); read only by the realign step, never by the pity count */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $declaredPity = null;

    /** Whether an S was the rate-up item, when a source says so (null = unknown, never "lost") */
    #[ORM\Column(nullable: true)]
    private ?bool $isUp = null;

    /** Record kind as sent by the API: self::KIND_DRAW for pulls, gift_* for bonus entries (never pulls) */
    #[ORM\Column(length: 32, options: ['default' => self::KIND_DRAW])]
    private string $kind = self::KIND_DRAW;

    /** Free Urgent Recruitment pull: it never counts toward the pity of the paid pulls */
    #[ORM\Column(options: ['default' => false])]
    private bool $isFree = false;

    /** Rerun number of the banner (the "#N" of the official page), null when the API gives none */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $poolVersion = null;

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

    public function setItemId(?string $itemId): static
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

    public function setRarity(?int $rarity): static
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

    public function getOrigin(): PullOrigin
    {
        return $this->origin;
    }

    public function setOrigin(PullOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getDeclaredPity(): ?int
    {
        return $this->declaredPity;
    }

    public function setDeclaredPity(?int $declaredPity): static
    {
        $this->declaredPity = $declaredPity;

        return $this;
    }

    public function isUp(): ?bool
    {
        return $this->isUp;
    }

    public function setIsUp(?bool $isUp): static
    {
        $this->isUp = $isUp;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * Label of a bonus entry kind (unknown kinds get a readable form of their code).
     */
    public static function getBonusLabel(string $kind): string
    {
        return self::BONUS_LABELS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /**
     * Whether the record is a pull (bonus entries are listed but never count).
     */
    public function isDraw(): bool
    {
        return $this->kind === self::KIND_DRAW;
    }

    public function isFree(): bool
    {
        return $this->isFree;
    }

    public function setIsFree(bool $isFree): static
    {
        $this->isFree = $isFree;

        return $this;
    }

    public function getPoolVersion(): ?int
    {
        return $this->poolVersion;
    }

    public function setPoolVersion(?int $poolVersion): static
    {
        $this->poolVersion = $poolVersion;

        return $this;
    }
}
