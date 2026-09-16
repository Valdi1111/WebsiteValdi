<?php

namespace App\HoyoverseBundle\Repository;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoyoverseDiaryEntry>
 */
class HoyoverseDiaryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoyoverseDiaryEntry::class);
    }
}
