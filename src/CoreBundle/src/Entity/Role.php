<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\UniqueConstraint(name: 'IDX_name', columns: ['name'])]
#[ORM\Entity(repositoryClass: RoleRepository::class)]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $bundle = null;

    /**
     * @var Collection<int, RoleHierarchy>
     */
    #[ORM\OneToMany(targetEntity: RoleHierarchy::class, mappedBy: 'parentRole', cascade: ['persist', 'remove'])]
    private Collection $childRoles;

    /**
     * @var Collection<int, RoleHierarchy>
     */
    #[ORM\OneToMany(targetEntity: RoleHierarchy::class, mappedBy: 'childRole', cascade: ['persist', 'remove'])]
    private Collection $parentRoles;

    /**
     * @var Collection<int, RolePermission>
     */
    #[ORM\OneToMany(targetEntity: RolePermission::class, mappedBy: 'role', cascade: ['persist', 'remove'])]
    private Collection $assignedPermissions;

    /**
     * @var Collection<int, UserRole>
     */
    #[ORM\OneToMany(targetEntity: UserRole::class, mappedBy: 'role', cascade: ['persist', 'remove'])]
    private Collection $assignedToUsers;

    public function __construct()
    {
        $this->childRoles = new ArrayCollection();
        $this->parentRoles = new ArrayCollection();
        $this->assignedPermissions = new ArrayCollection();
        $this->assignedToUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getBundle(): ?string
    {
        return $this->bundle;
    }

    public function setBundle(string $bundle): static
    {
        $this->bundle = $bundle;

        return $this;
    }

    /**
     * @return Collection<int, RoleHierarchy>
     */
    public function getChildRoles(): Collection
    {
        return $this->childRoles;
    }

    public function addChildRole(RoleHierarchy $childRole): static
    {
        if (!$this->childRoles->contains($childRole)) {
            $this->childRoles->add($childRole);
            $childRole->setParentRole($this);
        }

        return $this;
    }

    public function removeChildRole(RoleHierarchy $childRole): static
    {
        if ($this->childRoles->removeElement($childRole)) {
            // set the owning side to null (unless already changed)
            if ($childRole->getParentRole() === $this) {
                $childRole->setParentRole(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RoleHierarchy>
     */
    public function getParentRoles(): Collection
    {
        return $this->parentRoles;
    }

    public function addParentRole(RoleHierarchy $parentRole): static
    {
        if (!$this->parentRoles->contains($parentRole)) {
            $this->parentRoles->add($parentRole);
            $parentRole->setChildRole($this);
        }

        return $this;
    }

    public function removeParentRole(RoleHierarchy $parentRole): static
    {
        if ($this->parentRoles->removeElement($parentRole)) {
            // set the owning side to null (unless already changed)
            if ($parentRole->getChildRole() === $this) {
                $parentRole->setChildRole(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolePermission>
     */
    public function getAssignedPermissions(): Collection
    {
        return $this->assignedPermissions;
    }

    public function addAssignedPermission(RolePermission $assignedPermission): static
    {
        if (!$this->assignedPermissions->contains($assignedPermission)) {
            $this->assignedPermissions->add($assignedPermission);
            $assignedPermission->setRole($this);
        }

        return $this;
    }

    public function removeAssignedPermission(RolePermission $assignedPermission): static
    {
        if ($this->assignedPermissions->removeElement($assignedPermission)) {
            // set the owning side to null (unless already changed)
            if ($assignedPermission->getRole() === $this) {
                $assignedPermission->setRole(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UserRole>
     */
    public function getAssignedToUsers(): Collection
    {
        return $this->assignedToUsers;
    }

    public function addassignedToUser(UserRole $assignedToUser): static
    {
        if (!$this->assignedToUsers->contains($assignedToUser)) {
            $this->assignedToUsers->add($assignedToUser);
            $assignedToUser->setRole($this);
        }

        return $this;
    }

    public function removeassignedToUser(UserRole $assignedToUser): static
    {
        if ($this->assignedToUsers->removeElement($assignedToUser)) {
            // set the owning side to null (unless already changed)
            if ($assignedToUser->getRole() === $this) {
                $assignedToUser->setRole(null);
            }
        }

        return $this;
    }
}
