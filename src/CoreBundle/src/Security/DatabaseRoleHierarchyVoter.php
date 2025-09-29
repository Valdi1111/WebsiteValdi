<?php

namespace App\CoreBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class DatabaseRoleHierarchyVoter extends RoleVoter
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        string $prefix = 'ROLE_',
    ) {
        parent::__construct($prefix);
    }

    protected function extractRoles(TokenInterface $token): array
    {
        return $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
    }
}