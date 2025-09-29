<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\RoleHierarchy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoleHierarchy>
 */
class RoleHierarchyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleHierarchy::class);
    }

}
