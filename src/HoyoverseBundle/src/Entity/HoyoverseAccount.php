<?php

namespace App\HoyoverseBundle\Entity;

use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'FK_hoyoverse_account_user', columns: ['user_id'])]
#[ORM\Table(name: 'hoyoverse_account')]
#[ORM\Entity(repositoryClass: HoyoverseAccountRepository::class)]
class HoyoverseAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Ignore]
    #[ORM\Column(type: Types::TEXT)]
    private ?string $cookie = null;

    #[Ignore]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private ?\DateTimeInterface $addedAt = null;

    /**
     * @var Collection<int, HoyoverseGameProfile>
     */
    #[ORM\OneToMany(targetEntity: HoyoverseGameProfile::class, mappedBy: 'account', cascade: ['persist', 'remove'])]
    private Collection $gameProfiles;

    public function __construct()
    {
        $this->gameProfiles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCookie(): ?string
    {
        return $this->cookie;
    }

    public function setCookie(string $cookie): static
    {
        $this->cookie = $cookie;

        return $this;
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

    public function getAddedAt(): \DateTimeInterface
    {
        return $this->addedAt;
    }

    /**
     * @return Collection<int, HoyoverseGameProfile>
     */
    public function getGameProfiles(): Collection
    {
        return $this->gameProfiles;
    }

    public function addGameProfile(HoyoverseGameProfile $gameProfile): static
    {
        if (!$this->gameProfiles->contains($gameProfile)) {
            $this->gameProfiles->add($gameProfile);
            $gameProfile->setAccount($this);
        }

        return $this;
    }

    public function removeGameProfile(HoyoverseGameProfile $gameProfile): static
    {
        if ($this->gameProfiles->removeElement($gameProfile)) {
            // set the owning side to null (unless already changed)
            if ($gameProfile->getAccount() === $this) {
                $gameProfile->setAccount(null);
            }
        }

        return $this;
    }
}
