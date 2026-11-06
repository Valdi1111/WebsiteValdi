<?php

namespace App\GryphlineBundle\Entity;

use App\CoreBundle\Model\Game\GameProfileInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'IDX_game_id_game_uid', columns: ['game_id', 'game_uid'])]
#[ORM\Index(name: 'IDX_game_biz_game_uid', columns: ['game_biz', 'game_uid'])]
#[ORM\Index(name: 'IDX_game_id_gryphline_account', columns: ['game_id', 'gryphline_account_id'])]
#[ORM\Index(name: 'IDX_game_biz_gryphline_account', columns: ['game_biz', 'gryphline_account_id'])]
#[ORM\Index(name: 'FK_gryphline_game_profile_gryphline_account', columns: ['gryphline_account_id'])]
#[ORM\Table(name: 'gryphline_game_profile')]
#[ORM\Entity(repositoryClass: GryphlineGameProfileRepository::class)]
class GryphlineGameProfile implements GameProfileInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: GryphlineAccount::class, inversedBy: 'gameProfiles')]
    #[ORM\JoinColumn(name: 'gryphline_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?GryphlineAccount $account = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?int $gameId = null;

    #[ORM\Column(length: 50)]
    private ?string $gameBiz = 'endfield';

    #[ORM\Column(length: 50)]
    private ?string $gameName = 'Arknights: Endfield';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $regionName = null;

    #[ORM\Column(length: 50)]
    private ?string $gameUid = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    #[ORM\Column(length: 50)]
    private ?string $serverId = null;

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $iconUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $parsedRegion = null;

    #[ORM\Column(length: 50, options: ["default" => "UTC"])]
    private ?string $parsedTimezone = 'UTC';

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $skportCheckIn = true;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $staminaCheck = false;

    #[ORM\Column(options: ["default" => "-1"])]
    private ?int $staminaThreshold = -1;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $dailiesCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $weekliesCheck = false;

    #[ORM\Column]
    private array $notificationPlatforms = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $addedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?GryphlineAccount
    {
        return $this->account;
    }

    public function setAccount(?GryphlineAccount $account): static
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

    public function setGameId(?int $gameId): static
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

    public function setServerId(string $serverId): static
    {
        $this->serverId = $serverId;
        return $this;
    }

    public function getSkGameRole(): string
    {
        return sprintf('%d_%s_%s', $this->gameId ?? 3, $this->gameUid, $this->serverId);
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

    public function getParsedRegion(): ?string
    {
        return $this->parsedRegion ?? $this->region;
    }

    public function setParsedRegion(?string $parsedRegion): static
    {
        $this->parsedRegion = $parsedRegion;
        return $this;
    }

    public function updateParsedRegion(): static
    {
        $region = strtolower((string) ($this->getRegion() ?? ''));
        $this->parsedRegion = match ($region) {
            'us', 'americas / europe', '3', 'eu', 'na' => 'EU',
            'cn', 'zh', 'china', '1', '6' => 'CN',
            default => 'EU',
        };

        return $this;
    }

    public function getParsedTimezone(): string
    {
        return $this->parsedTimezone ?? 'UTC';
    }

    public function setParsedTimezone(string $parsedTimezone): static
    {
        $this->parsedTimezone = $parsedTimezone;
        return $this;
    }

    public function updateParsedTimezone(): static
    {
        $region = strtolower((string) ($this->getRegion() ?? ''));
        $this->parsedTimezone = match ($region) {
            'us', 'americas / europe', '3', 'eu', 'na' => 'EU',
            'cn', 'zh', 'china', '1', '6' => 'CN',
            default => 'EU',
        };

        return $this;
    }

    public function isSkportCheckIn(): ?bool
    {
        return $this->skportCheckIn;
    }

    public function setSkportCheckIn(bool $skportCheckIn): static
    {
        $this->skportCheckIn = $skportCheckIn;
        return $this;
    }

    public function isStaminaCheck(): ?bool
    {
        return $this->staminaCheck;
    }

    public function setStaminaCheck(bool $staminaCheck): static
    {
        $this->staminaCheck = $staminaCheck;
        return $this;
    }

    public function getStaminaThreshold(): int
    {
        return $this->staminaThreshold ?? -1;
    }

    public function setStaminaThreshold(int $staminaThreshold): static
    {
        $this->staminaThreshold = $staminaThreshold;
        return $this;
    }

    public function isDailiesCheck(): ?bool
    {
        return $this->dailiesCheck;
    }

    public function setDailiesCheck(bool $dailiesCheck): static
    {
        $this->dailiesCheck = $dailiesCheck;
        return $this;
    }

    public function isWeekliesCheck(): ?bool
    {
        return $this->weekliesCheck;
    }

    public function setWeekliesCheck(bool $weekliesCheck): static
    {
        $this->weekliesCheck = $weekliesCheck;
        return $this;
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

    public function getAddedAt(): ?\DateTimeInterface
    {
        return $this->addedAt;
    }
}
