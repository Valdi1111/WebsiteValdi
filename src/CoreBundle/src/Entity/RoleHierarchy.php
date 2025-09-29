<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\RoleHierarchyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(name: 'FK_role_hierarchy_parent_role', columns: ['parent_role_id'])]
#[ORM\Index(name: 'FK_role_hierarchy_child_role', columns: ['child_role_id'])]
#[ORM\UniqueConstraint(name: 'IDX_parent_role_child_role', columns: ['parent_role_id', 'child_role_id'])]
#[ORM\Entity(repositoryClass: RoleHierarchyRepository::class)]
class RoleHierarchy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'childRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Role $parentRole = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'parentRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Role $childRole = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentRole(): ?Role
    {
        return $this->parentRole;
    }

    public function setParentRole(?Role $parentRole): static
    {
        $this->parentRole = $parentRole;

        return $this;
    }

    public function getChildRole(): ?Role
    {
        return $this->childRole;
    }

    public function setChildRole(?Role $childRole): static
    {
        $this->childRole = $childRole;

        return $this;
    }
}
