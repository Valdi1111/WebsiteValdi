<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Entity\RolePermission;
use App\CoreBundle\Repository\RoleRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Cache\CacheInterface;

class DatabasePermissionHierarchy implements PermissionHierarchyInterface
{
    /** @var array<string, list<string>> */
    protected array $map;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly RoleRepository         $roleRepo,
        CacheInterface                          $cacheSecurityHierarchy
    ) {
        $this->map = $cacheSecurityHierarchy->get("permissions", [$this, 'buildPermissionMap']);
    }

    public function getReachablePermissionNames(array $roles): array
    {
        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($roles);
        $permissions = [];
        foreach ($reachableRoles as $role) {
            if (!isset($this->map[$role])) {
                continue;
            }
            array_push($permissions, ...$this->map[$role]);
        }
        return array_values(array_unique($permissions));
    }

    public function buildPermissionMap(): array
    {
        $map = [];
        $roles = $this->roleRepo->findRolesWithPermissions();
        foreach ($roles as $role) {
            $map[$role->getName()] = $role->getAssignedPermissions()
                ->map(fn(RolePermission $p) => "{$p->getPermission()->getNamespace()}.{$p->getPermission()->getName()}")
                ->toArray();
        }
        return $map;
    }
}