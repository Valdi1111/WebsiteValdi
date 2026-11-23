<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Profile\RoleView;
use App\CoreBundle\Repository\RoleRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Roles of a user as the frontend sees them: the assigned ones plus those inherited through the role hierarchy
 * (the database hierarchy, DatabaseRoleHierarchy), the same set the security voters check.
 */
class UserRoleResolver
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    /**
     * @return list<string> assigned and inherited role names, sorted
     */
    public function getReachableRoleNames(User $user): array
    {
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
        sort($roles);

        return $roles;
    }

    /**
     * @return list<RoleView> assigned and inherited roles with their display name and bundle, sorted by name
     */
    public function getRoleViews(User $user): array
    {
        $assigned = $user->getRoles();
        $names = $this->getReachableRoleNames($user);

        $entities = [];
        foreach ($this->roleRepository->findBy(['name' => $names]) as $role) {
            /** @var Role $role */
            $entities[$role->getName()] = $role;
        }

        return array_map(
            static function (string $name) use ($entities, $assigned): RoleView {
                $role = $entities[$name] ?? null;

                return new RoleView($name, $role?->getDescription() ?? $name, $role?->getBundle(), in_array($name, $assigned, true));
            },
            $names,
        );
    }
}
