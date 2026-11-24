<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * @return list<Role> every role, the site-wide ones (no bundle) first, then by bundle and name
     */
    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('r')
            // MySQL sorts NULL first in ascending order
            ->orderBy('r.bundle')
            ->addOrderBy('r.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Role[]
     */
    public function findRolesWithChildren(): array
    {
        return $this->createQueryBuilder('r')
            ->where('SIZE(r.childRoles) > 0')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Role[]
     */
    public function findRolesWithPermissions(): array
    {
        return $this->createQueryBuilder('r')
            ->where('SIZE(r.assignedPermissions) > 0')
            ->getQuery()
            ->getResult();
    }

}
