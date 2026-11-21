<?php

namespace App\PerfectworldBundle\Repository;

use App\PerfectworldBundle\Entity\PerfectworldAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfectworldAccount>
 */
class PerfectworldAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfectworldAccount::class);
    }
}
