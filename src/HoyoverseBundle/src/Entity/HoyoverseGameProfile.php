<?php

namespace App\HoyoverseBundle\Entity;

use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'IDX_game_id_game_uid', columns: ['game_id', 'game_uid'])]
#[ORM\Index(name: 'IDX_game_biz_game_uid', columns: ['game_biz', 'game_uid'])]
#[ORM\Index(name: 'IDX_game_id_hoyoverse_account', columns: ['game_id', 'hoyoverse_account_id'])]
#[ORM\Index(name: 'IDX_game_biz_hoyoverse_account', columns: ['game_biz', 'hoyoverse_account_id'])]
#[ORM\Index(name: 'FK_hoyoverse_game_profile_hoyoverse_account', columns: ['hoyoverse_account_id'])]
#[ORM\Table(name: 'hoyoverse_game_profile')]
#[ORM\Entity(repositoryClass: HoyoverseGameProfileRepository::class)]
class HoyoverseGameProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: HoyoverseAccount::class, inversedBy: 'gameProfiles')]
    #[ORM\JoinColumn(name: 'hoyoverse_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?HoyoverseAccount $account = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $active = true;

    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column(length: 50)]
    private ?string $gameBiz = null;

    #[ORM\Column(length: 50)]
    private ?string $gameName = null;

    #[ORM\Column(length: 50)]
    private ?string $region = null;

    #[ORM\Column(length: 50)]
    private ?string $regionName = null;

    #[ORM\Column(length: 50)]
    private ?string $gameUid = null;

    #[ORM\Column(length: 50)]
    private ?string $nickname = null;

    #[ORM\Column]
    private ?int $level = null;

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $hoyolabUrl = null;

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $iconUrl = null;

    #[ORM\Column(length: 50)]
    private ?string $parsedRegion = null;

    #[ORM\Column(length: 50)]
    private ?string $parsedTimezone = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $hoyolabCheckIn = true;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $hoyolabMissedCheckIn = true;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $codeRedeem = true;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $staminaCheck = false;

    #[ORM\Column(options: ["default" => "-1"])]
    private ?int $staminaThreshold = -1;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $expeditionCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $realmCurrencyCheck = false;

    #[ORM\Column(options: ["default" => "-1"])]
    private ?int $realmCurrencyThreshold = -1;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $shopStatusCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $mimoCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $mimoRedeem = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $mimoRedeemDraw = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $mimoLottery = false;

    #[ORM\Column(options: ["default" => "-1"])]
    private ?int $mimoReservePoints = -1;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $hilichurlCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $hilichurlRedeem = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $dailiesCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $weekliesCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $endgamesCheck = false;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $syncDiary = false;

    #[ORM\Column]
    private array $notificationPlatforms = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $addedAt = null;

    /**
     * @var Collection<int, HoyoverseDiaryEntry>
     */
    #[Ignore]
    #[ORM\OneToMany(targetEntity: HoyoverseDiaryEntry::class, mappedBy: 'gameProfile', cascade: ['persist', 'remove'])]
    private Collection $diaryEntries;

    public function __construct()
    {
        $this->diaryEntries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?HoyoverseAccount
    {
        return $this->account;
    }

    public function setAccount(?HoyoverseAccount $account): static
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

    public function setGameBiz(?string $gameBiz): static
    {
        $this->gameBiz = $gameBiz;

        return $this;
    }

    public function getGameName(): ?string
    {
        return $this->gameName;
    }

    public function setGameName(?string $gameName): static
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

    public function setGameUid(?string $gameUid): static
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

    public function getHoyolabUrl(): ?string
    {
        return $this->hoyolabUrl;
    }

    public function setHoyolabUrl(?string $hoyolabUrl): static
    {
        $this->hoyolabUrl = $hoyolabUrl;

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

    public function setParsedTimezone(?string $parsedTimezone): static
    {
        $this->parsedTimezone = $parsedTimezone;

        return $this;
    }

    public function getHoyolabCheckIn(): ?bool
    {
        return $this->hoyolabCheckIn;
    }

    public function setHoyolabCheckIn(?bool $hoyolabCheckIn): static
    {
        $this->hoyolabCheckIn = $hoyolabCheckIn;

        return $this;
    }

    public function getHoyolabMissedCheckIn(): ?bool
    {
        return $this->hoyolabMissedCheckIn;
    }

    public function setHoyolabMissedCheckIn(?bool $hoyolabMissedCheckIn): static
    {
        $this->hoyolabMissedCheckIn = $hoyolabMissedCheckIn;

        return $this;
    }

    public function isCodeRedeem(): ?bool
    {
        return $this->codeRedeem;
    }

    public function setCodeRedeem(bool $codeRedeem): static
    {
        $this->codeRedeem = $codeRedeem;

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

    public function getStaminaThreshold(): ?int
    {
        return $this->staminaThreshold;
    }

    public function setStaminaThreshold(int $staminaThreshold): static
    {
        $this->staminaThreshold = $staminaThreshold;

        return $this;
    }

    public function isExpeditionCheck(): ?bool
    {
        return $this->expeditionCheck;
    }

    public function setExpeditionCheck(bool $expeditionCheck): static
    {
        $this->expeditionCheck = $expeditionCheck;

        return $this;
    }

    public function isRealmCurrencyCheck(): ?bool
    {
        return $this->realmCurrencyCheck;
    }

    public function setRealmCurrencyCheck(bool $realmCurrencyCheck): static
    {
        $this->realmCurrencyCheck = $realmCurrencyCheck;

        return $this;
    }

    public function getRealmCurrencyThreshold(): ?int
    {
        return $this->realmCurrencyThreshold;
    }

    public function setRealmCurrencyThreshold(int $realmCurrencyThreshold): static
    {
        $this->realmCurrencyThreshold = $realmCurrencyThreshold;

        return $this;
    }

    public function getShopStatusCheck(): ?bool
    {
        return $this->shopStatusCheck;
    }

    public function setShopStatusCheck(?bool $shopStatusCheck): static
    {
        $this->shopStatusCheck = $shopStatusCheck;

        return $this;
    }

    public function isMimoCheck(): ?bool
    {
        return $this->mimoCheck;
    }

    public function setMimoCheck(bool $mimoCheck): static
    {
        $this->mimoCheck = $mimoCheck;

        return $this;
    }

    public function isMimoRedeem(): ?bool
    {
        return $this->mimoRedeem;
    }

    public function setMimoRedeem(bool $mimoRedeem): static
    {
        $this->mimoRedeem = $mimoRedeem;

        return $this;
    }

    public function isMimoRedeemDraw(): ?bool
    {
        return $this->mimoRedeemDraw;
    }

    public function setMimoRedeemDraw(bool $mimoRedeemDraw): static
    {
        $this->mimoRedeemDraw = $mimoRedeemDraw;

        return $this;
    }

    public function isMimoLottery(): ?bool
    {
        return $this->mimoLottery;
    }

    public function setMimoLottery(bool $mimoLottery): static
    {
        $this->mimoLottery = $mimoLottery;

        return $this;
    }

    public function getMimoReservePoints(): ?int
    {
        return $this->mimoReservePoints;
    }

    public function setMimoReservePoints(int $mimoReservePoints): static
    {
        $this->mimoReservePoints = $mimoReservePoints;

        return $this;
    }

    public function isHilichurlCheck(): ?bool
    {
        return $this->hilichurlCheck;
    }

    public function setHilichurlCheck(bool $hilichurlCheck): static
    {
        $this->hilichurlCheck = $hilichurlCheck;

        return $this;
    }

    public function isHilichurlRedeem(): ?bool
    {
        return $this->hilichurlRedeem;
    }

    public function setHilichurlRedeem(bool $hilichurlRedeem): static
    {
        $this->hilichurlRedeem = $hilichurlRedeem;

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

    public function isEndgamesCheck(): ?bool
    {
        return $this->endgamesCheck;
    }

    public function setEndgamesCheck(?bool $endgamesCheck): static
    {
        $this->endgamesCheck = $endgamesCheck;
        return $this;
    }

    public function isSyncDiary(): ?bool
    {
        return $this->syncDiary;
    }

    public function setSyncDiary(?bool $syncDiary): static
    {
        $this->syncDiary = $syncDiary;
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

    public function getAddedAt(): \DateTimeInterface
    {
        return $this->addedAt;
    }

    /**
     * @return Collection<int, HoyoverseDiaryEntry>
     */
    public function getDiaryEntries(): Collection
    {
        return $this->diaryEntries;
    }

    public function addDiaryEntry(HoyoverseDiaryEntry $diaryEntry): static
    {
        if (!$this->diaryEntries->contains($diaryEntry)) {
            $this->diaryEntries->add($diaryEntry);
            $diaryEntry->setGameProfile($this);
        }

        return $this;
    }

    public function removeDiaryEntry(HoyoverseDiaryEntry $diaryEntry): static
    {
        if ($this->diaryEntries->removeElement($diaryEntry)) {
            // set the owning side to null (unless already changed)
            if ($diaryEntry->getGameProfile() === $this) {
                $diaryEntry->setGameProfile(null);
            }
        }

        return $this;
    }

    public function updateParsedRegion(): static
    {
        return $this->setParsedRegion(match($this->getRegion()) {
            "os_cht", "prod_gf_sg", "prod_official_cht" => "TW/HK/MO",
            "os_asia", "prod_gf_jp", "prod_official_asia" => "SEA",
            "eur01", "os_euro", "prod_gf_eu", "prod_official_eur" => "EU",
            "usa01", "os_usa", "prod_gf_us", "prod_official_usa" => "NA",
            default => "Unknown",
        });
    }

    public function updateParsedTimezone(): static
    {
        return $this->setParsedTimezone(match ($this->getRegion()) {
            "os_cht", "os_asia", "prod_gf_sg", "prod_gf_jp", "prod_official_cht", "prod_official_asia" => "SEA",
            "eur01", "os_euro", "prod_gf_eu", "prod_official_eur" => "EU",
            "usa01", "os_usa", "prod_gf_us", "prod_official_usa" => "NA",
            default => "Unknown",
        });
    }
}
