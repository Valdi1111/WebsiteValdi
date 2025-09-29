<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\UserRoleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'FK_user_role_user', columns: ['user_id'])]
#[ORM\Index(name: 'FK_user_role_role', columns: ['role_id'])]
#[ORM\UniqueConstraint(name: 'IDX_user_role', columns: ['user_id', 'role_id'])]
#[ORM\Entity(repositoryClass: UserRoleRepository::class)]
class UserRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'assignedRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'assignedToUsers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Role $role = null;

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

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): static
    {
        $this->role = $role;

        return $this;
    }
}
