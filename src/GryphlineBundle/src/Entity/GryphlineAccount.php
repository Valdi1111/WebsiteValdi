<?php

namespace App\GryphlineBundle\Entity;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Game\GameAccountInterface;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'FK_gryphline_account_user', columns: ['user_id'])]
#[ORM\Table(name: 'gryphline_account')]
#[ORM\Entity(repositoryClass: GryphlineAccountRepository::class)]
class GryphlineAccount implements GameAccountInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[Ignore]
    #[ORM\Column(type: Types::TEXT)]
    private ?string $accountToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sessionCred = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sessionSalt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $sessionExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $addedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastTokenRefreshedAt = null;

    #[ORM\Column(options: ["default" => "0"])]
    private ?bool $tokenRefreshFailed = false;

    /**
     * @var Collection<int, GryphlineGameProfile>
     */
    #[ORM\OneToMany(targetEntity: GryphlineGameProfile::class, mappedBy: 'account', cascade: ['persist', 'remove'])]
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

    public function getAccountToken(): ?string
    {
        return $this->accountToken;
    }

    public function setAccountToken(string $accountToken): static
    {
        $this->accountToken = $accountToken;
        return $this;
    }

    public function getSessionCred(): ?string
    {
        return $this->sessionCred;
    }

    public function setSessionCred(?string $sessionCred): static
    {
        $this->sessionCred = $sessionCred;
        return $this;
    }

    public function getSessionSalt(): ?string
    {
        return $this->sessionSalt;
    }

    public function setSessionSalt(?string $sessionSalt): static
    {
        $this->sessionSalt = $sessionSalt;
        return $this;
    }

    public function getSessionExpiresAt(): ?\DateTimeInterface
    {
        return $this->sessionExpiresAt;
    }

    public function setSessionExpiresAt(?\DateTimeInterface $sessionExpiresAt): static
    {
        $this->sessionExpiresAt = $sessionExpiresAt;
        return $this;
    }

    public function isSessionValid(): bool
    {
        if ($this->sessionCred === null || $this->sessionSalt === null || $this->sessionExpiresAt === null) {
            return false;
        }

        return $this->sessionExpiresAt > new \DateTimeImmutable();
    }

    public function getAddedAt(): \DateTimeInterface
    {
        return $this->addedAt;
    }

    public function getLastTokenRefreshedAt(): ?\DateTimeInterface
    {
        return $this->lastTokenRefreshedAt;
    }

    public function setLastTokenRefreshedAt(?\DateTimeInterface $lastTokenRefreshedAt): static
    {
        $this->lastTokenRefreshedAt = $lastTokenRefreshedAt;
        return $this;
    }

    public function isTokenRefreshFailed(): ?bool
    {
        return $this->tokenRefreshFailed;
    }

    public function setTokenRefreshFailed(bool $tokenRefreshFailed): static
    {
        $this->tokenRefreshFailed = $tokenRefreshFailed;
        return $this;
    }

    /**
     * @return Collection<int, GryphlineGameProfile>
     */
    public function getGameProfiles(): Collection
    {
        return $this->gameProfiles;
    }

    public function addGameProfile(GryphlineGameProfile $gameProfile): static
    {
        if (!$this->gameProfiles->contains($gameProfile)) {
            $this->gameProfiles->add($gameProfile);
            $gameProfile->setAccount($this);
        }

        return $this;
    }

    public function removeGameProfile(GryphlineGameProfile $gameProfile): static
    {
        if ($this->gameProfiles->removeElement($gameProfile)) {
            if ($gameProfile->getAccount() === $this) {
                $gameProfile->setAccount(null);
            }
        }

        return $this;
    }
}
