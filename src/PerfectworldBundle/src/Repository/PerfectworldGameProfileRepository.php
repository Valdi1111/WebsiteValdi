<?php

namespace App\PerfectworldBundle\Repository;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfectworldGameProfile>
 */
class PerfectworldGameProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfectworldGameProfile::class);
    }
}
