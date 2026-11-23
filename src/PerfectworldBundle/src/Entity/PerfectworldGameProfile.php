<?php

namespace App\PerfectworldBundle\Entity;

use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Game role of a Perfect World account (from the SDK role list).
 */
#[ORM\Index(name: 'FK_perfectworld_game_profile_account', columns: ['perfectworld_account_id'])]
#[ORM\Index(name: 'IDX_perfectworld_game_profile_game_uid', columns: ['game_id', 'game_uid'])]
#[ORM\UniqueConstraint(name: 'IDX_perfectworld_game_profile_account_game_uid', columns: ['perfectworld_account_id', 'game_uid'])]
#[ORM\Table(name: 'perfectworld_game_profile')]
#[ORM\Entity(repositoryClass: PerfectworldGameProfileRepository::class)]
class PerfectworldGameProfile implements GameProfileInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: PerfectworldAccount::class, inversedBy: 'gameProfiles')]
    #[ORM\JoinColumn(name: 'perfectworld_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PerfectworldAccount $account = null;

    #[ORM\Column(options: ['default' => '1'])]
    private ?bool $active = true;

    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column(length: 50)]
    private ?string $gameBiz = null;

    #[ORM\Column(length: 50)]
    private ?string $gameName = null;

    #[ORM\Column(length: 50)]
    private ?string $gameUid = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $serverId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $regionName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $parsedRegion = null;

    #[ORM\Column(length: 50, options: ['default' => 'UTC'])]
    private ?string $parsedTimezone = 'UTC';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $avatarId = null;

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $iconUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstLoginAt = null;

    /**
     * Not used yet (no notifications for this provider): hidden from the API.
     */
    #[Ignore]
    #[ORM\Column]
    private array $notificationPlatforms = [];

    /**
     * @var Collection<int, PerfectworldPull>
     */
    #[Ignore]
    #[ORM\OneToMany(targetEntity: PerfectworldPull::class, mappedBy: 'gameProfile', cascade: ['persist', 'remove'])]
    private Collection $pulls;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ['default' => new CurrentTimestamp()])]
    private ?\DateTimeInterface $addedAt = null;

    public function __construct()
    {
        $this->pulls = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getAccount(): ?GameAccountInterface
    {
        return $this->account;
    }

    public function setAccount(?PerfectworldAccount $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getGameId(): ?int
    {
        return $this->gameId;
    }

    public function setGameId(int $gameId): static
    {
        $this->gameId = $gameId;

        return $this;
    }

    public function getGameBiz(): ?string
    {
        return $this->gameBiz;
    }

    public function setGameBiz(string $gameBiz): static
    {
        $this->gameBiz = $gameBiz;

        return $this;
    }

    public function getGameName(): ?string
    {
        return $this->gameName;
    }

    public function setGameName(string $gameName): static
    {
        $this->gameName = $gameName;

        return $this;
    }

    public function getGameUid(): ?string
    {
        return $this->gameUid;
    }

    public function setGameUid(string $gameUid): static
    {
        $this->gameUid = $gameUid;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): static
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getServerId(): ?string
    {
        return $this->serverId;
    }

    public function setServerId(?string $serverId): static
    {
        $this->serverId = $serverId;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getRegionName(): ?string
    {
        return $this->regionName;
    }

    public function setRegionName(?string $regionName): static
    {
        $this->regionName = $regionName;

        return $this;
    }

    public function getParsedRegion(): ?string
    {
        return $this->parsedRegion;
    }

    public function setParsedRegion(?string $parsedRegion): static
    {
        $this->parsedRegion = $parsedRegion;

        return $this;
    }

    public function getParsedTimezone(): ?string
    {
        return $this->parsedTimezone;
    }

    public function setParsedTimezone(string $parsedTimezone): static
    {
        $this->parsedTimezone = $parsedTimezone;

        return $this;
    }

    public function getAvatarId(): ?string
    {
        return $this->avatarId;
    }

    public function setAvatarId(?string $avatarId): static
    {
        $this->avatarId = $avatarId;

        return $this;
    }

    public function getIconUrl(): ?string
    {
        return $this->iconUrl;
    }

    public function setIconUrl(?string $iconUrl): static
    {
        $this->iconUrl = $iconUrl;

        return $this;
    }

    #[Ignore]
    public function getProviderIconUrl(): ?string
    {
        return null;
    }

    public function getFirstLoginAt(): ?\DateTimeImmutable
    {
        return $this->firstLoginAt;
    }

    public function setFirstLoginAt(?\DateTimeImmutable $firstLoginAt): static
    {
        $this->firstLoginAt = $firstLoginAt;

        return $this;
    }

    #[Ignore]
    public function getStaminaThreshold(): ?int
    {
        return null;
    }

    public function getNotificationPlatforms(): array
    {
        return $this->notificationPlatforms;
    }

    public function setNotificationPlatforms(array $notificationPlatforms): static
    {
        $this->notificationPlatforms = $notificationPlatforms;

        return $this;
    }

    /**
     * @return Collection<int, PerfectworldPull>
     */
    public function getPulls(): Collection
    {
        return $this->pulls;
    }

    public function getAddedAt(): ?\DateTimeInterface
    {
        return $this->addedAt;
    }
}
