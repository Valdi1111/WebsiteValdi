<?php

namespace App\HoyoverseBundle\Entity;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'hoyoverse_pull')]
#[ORM\UniqueConstraint(name: 'IDX_hoyoverse_game_profile_id_pull_id', columns: ['hoyoverse_game_profile_id', 'pull_id'])]
#[ORM\Index(name: 'FK_hoyoverse_pull_hoyoverse_game_profile', columns: ['hoyoverse_game_profile_id'])]
#[ORM\Index(name: 'IDX_hoyoverse_game_profile_id_gacha_type', columns: ['hoyoverse_game_profile_id', 'gacha_type'])]
#[ORM\Index(name: 'IDX_hoyoverse_game_profile_id_rarity', columns: ['hoyoverse_game_profile_id', 'rarity'])]
#[ORM\Index(name: 'IDX_hoyoverse_game_profile_id_pulled_at', columns: ['hoyoverse_game_profile_id', 'pulled_at'])]
#[ORM\Entity(repositoryClass: HoyoversePullRepository::class)]
class HoyoversePull
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HoyoverseGameProfile::class, inversedBy: 'pulls')]
    #[ORM\JoinColumn(name: 'hoyoverse_game_profile_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?HoyoverseGameProfile $gameProfile = null;

    #[ORM\Column(length: 64)]
    private ?string $pullId = null;

    #[ORM\Column(length: 16)]
    private ?string $gachaType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $gachaName = null;

    #[ORM\Column(length: 32)]
    private ?string $itemType = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $itemId = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $rarity = null;

    /**
     * Game server wall-clock time (e.g. "2024-03-31 02:30:00" on an Europe server), read back as UTC so DST gaps never shift it.
     */
    #[ORM\Column(type: WallClockDateTimeImmutableType::NAME)]
    private ?\DateTimeImmutable $pulledAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(length: 16, enumType: PullOrigin::class, options: ['default' => 'recorded'])]
    private PullOrigin $origin = PullOrigin::Recorded;

    /** Real pity declared by the user on an S row (always set on manual rows); never read by the pity count */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $declaredPity = null;

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

    public function getPullId(): ?string
    {
        return $this->pullId;
    }

    public function setPullId(string $pullId): static
    {
        $this->pullId = $pullId;

        return $this;
    }

    public function getGachaType(): ?string
    {
        return $this->gachaType;
    }

    public function setGachaType(string $gachaType): static
    {
        $this->gachaType = $gachaType;

        return $this;
    }

    public function getGachaName(): ?string
    {
        return $this->gachaName;
    }

    public function setGachaName(?string $gachaName): static
    {
        $this->gachaName = $gachaName;

        return $this;
    }

    public function getItemType(): ?string
    {
        return $this->itemType;
    }

    public function setItemType(string $itemType): static
    {
        $this->itemType = $itemType;

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
}
