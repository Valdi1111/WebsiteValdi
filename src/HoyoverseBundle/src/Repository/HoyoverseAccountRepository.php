<?php

namespace App\HoyoverseBundle\Repository;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoyoverseAccount>
 */
class HoyoverseAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoyoverseAccount::class);
    }
}
