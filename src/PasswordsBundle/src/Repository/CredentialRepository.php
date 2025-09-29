<?php

namespace App\PasswordsBundle\Repository;

use App\CoreBundle\Repository\ITableRepository;
use App\CoreBundle\Repository\TableRepositoryTrait;
use App\PasswordsBundle\Entity\Credential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Credential>
 *
 * @method Credential|null find($id, $lockMode = null, $lockVersion = null)
 * @method Credential|null findOneBy(array $criteria, array $orderBy = null)
 * @method Credential[]    findAll()
 * @method Credential[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CredentialRepository extends ServiceEntityRepository implements ITableRepository
{
    use TableRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Credential::class);
    }

}
