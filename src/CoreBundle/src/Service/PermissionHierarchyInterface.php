<?php

namespace App\CoreBundle\Service;

/**
 * PermissionHierarchyInterface is the interface for a permission hierarchy.
 */
interface PermissionHierarchyInterface
{
    /**
     * @param string[] $roles
     *
     * @return string[]
     */
    public function getReachablePermissionNames(array $roles): array;
}