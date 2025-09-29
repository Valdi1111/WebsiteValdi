<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\RolePermissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'FK_role_permission_role', columns: ['role_id'])]
#[ORM\Index(name: 'FK_role_permission_permission', columns: ['permission_id'])]
#[ORM\UniqueConstraint(name: 'IDX_role_permission', columns: ['role_id', 'permission_id'])]
#[ORM\Entity(repositoryClass: RolePermissionRepository::class)]
class RolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'assignedPermissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Role $role = null;

    #[ORM\ManyToOne(targetEntity: Permission::class, inversedBy: 'assignedToRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Permission $permission = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPermission(): ?Permission
    {
        return $this->permission;
    }

    public function setPermission(?Permission $permission): static
    {
        $this->permission = $permission;

        return $this;
    }
}
