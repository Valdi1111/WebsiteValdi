<?php

namespace App\PerfectworldBundle\Entity;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Perfect World Games account, authenticated through the web SDK token (uid + token).
 */
#[ORM\Index(name: 'FK_perfectworld_account_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'IDX_perfectworld_account_user_pw_uid', columns: ['user_id', 'pw_uid'])]
#[ORM\Table(name: 'perfectworld_account')]
#[ORM\Entity(repositoryClass: PerfectworldAccountRepository::class)]
class PerfectworldAccount implements GameAccountInterface
{
    public const string LOGIN_METHOD_EMAIL_CODE = 'email_code';
    public const string LOGIN_METHOD_TOKEN = 'token';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private ?string $pwUid = null;

    #[Ignore]
    #[ORM\Column(type: EncryptedTextType::NAME)]
    private ?string $token = null;

    /**
     * Device id sent with the last successful login: token checks must reuse it.
     */
    #[Ignore]
    #[ORM\Column(length: 64)]
    private ?string $ndid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 16)]
    private ?string $loginMethod = self::LOGIN_METHOD_EMAIL_CODE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(options: ['default' => '0'])]
    private ?bool $tokenInvalid = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ['default' => new CurrentTimestamp()])]
    private ?\DateTimeInterface $addedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /**
     * @var Collection<int, PerfectworldGameProfile>
     */
    #[ORM\OneToMany(targetEntity: PerfectworldGameProfile::class, mappedBy: 'account', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $gameProfiles;

    public function __construct()
    {
        $this->gameProfiles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPwUid(): ?string
    {
        return $this->pwUid;
    }

    public function setPwUid(string $pwUid): static
    {
        $this->pwUid = $pwUid;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getNdid(): ?string
    {
        return $this->ndid;
    }

    public function setNdid(string $ndid): static
    {
        $this->ndid = $ndid;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getLoginMethod(): ?string
    {
        return $this->loginMethod;
    }

    public function setLoginMethod(string $loginMethod): static
    {
        $this->loginMethod = $loginMethod;

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function isTokenInvalid(): ?bool
    {
        return $this->tokenInvalid;
    }

    public function setTokenInvalid(bool $tokenInvalid): static
    {
        $this->tokenInvalid = $tokenInvalid;

        return $this;
    }

    public function getAddedAt(): ?\DateTimeInterface
    {
        return $this->addedAt;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    /**
     * @return Collection<int, PerfectworldGameProfile>
     */
    public function getGameProfiles(): Collection
    {
        return $this->gameProfiles;
    }

    public function addGameProfile(PerfectworldGameProfile $gameProfile): static
    {
        if (!$this->gameProfiles->contains($gameProfile)) {
            $this->gameProfiles->add($gameProfile);
            $gameProfile->setAccount($this);
        }

        return $this;
    }

    public function removeGameProfile(PerfectworldGameProfile $gameProfile): static
    {
        $this->gameProfiles->removeElement($gameProfile);

        return $this;
    }
}
