<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\PermissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\UniqueConstraint(name: 'IDX_namespace_name', columns: ['namespace', 'name'])]
#[ORM\Entity(repositoryClass: PermissionRepository::class)]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $namespace = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $bundle = null;

    /**
     * @var Collection<int, RolePermission>
     */
    #[ORM\OneToMany(targetEntity: RolePermission::class, mappedBy: 'permission', cascade: ['persist', 'remove'])]
    private Collection $assignedToRoles;

    public function __construct()
    {
        $this->assignedToRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): static
    {
        $this->namespace = $namespace;

        return $this;
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
     * @return Collection<int, RolePermission>
     */
    public function getAssignedToRoles(): Collection
    {
        return $this->assignedToRoles;
    }

    public function addAssignedToRole(RolePermission $assignedToRole): static
    {
        if (!$this->assignedToRoles->contains($assignedToRole)) {
            $this->assignedToRoles->add($assignedToRole);
            $assignedToRole->setPermission($this);
        }

        return $this;
    }

    public function removeAssignedToRole(RolePermission $assignedToRole): static
    {
        if ($this->assignedToRoles->removeElement($assignedToRole)) {
            // set the owning side to null (unless already changed)
            if ($assignedToRole->getPermission() === $this) {
                $assignedToRole->setPermission(null);
            }
        }

        return $this;
    }
}
