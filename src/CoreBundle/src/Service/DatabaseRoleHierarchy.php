<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Entity\RoleHierarchy;
use App\CoreBundle\Repository\RoleRepository;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsDecorator('security.role_hierarchy', priority: 100)]
class DatabaseRoleHierarchy implements RoleHierarchyInterface
{
    /** @var array<string, list<string>> */
    protected array $map;

    public function __construct(
        private readonly RoleRepository $roleRepo,
        CacheInterface                  $cacheSecurityHierarchy
    ) {
        $this->map = $cacheSecurityHierarchy->get("roles", [$this, 'buildRoleMap']);
    }

    public function getReachableRoleNames(array $roles): array
    {
        $reachableRoles = $roles;
        foreach ($roles as $role) {
            if (!isset($this->map[$role])) {
                continue;
            }
            array_push($reachableRoles, ...$this->map[$role]);
        }
        return array_values(array_unique($reachableRoles));
    }

    public function buildRoleMap(): array
    {
        $hierarchy = [];
        $roles = $this->roleRepo->findRolesWithChildren();
        foreach ($roles as $role) {
            $hierarchy[$role->getName()] = $role->getChildRoles()
                ->map(fn(RoleHierarchy $h) => $h->getChildRole()->getName())
                ->toArray();
        }

        $map = [];
        foreach ($hierarchy as $main => $roles) {
            $map[$main] = $roles;
            $visited = [];
            $additionalRoles = $roles;
            while ($role = array_shift($additionalRoles)) {
                if (!isset($hierarchy[$role])) {
                    continue;
                }

                $visited[] = $role;
                foreach ($hierarchy[$role] as $roleToAdd) {
                    $map[$main][] = $roleToAdd;
                }

                foreach (array_diff($hierarchy[$role], $visited) as $additionalRole) {
                    $additionalRoles[] = $additionalRole;
                }
            }
            $map[$main] = array_unique($map[$main]);
        }
        return $map;
    }
}