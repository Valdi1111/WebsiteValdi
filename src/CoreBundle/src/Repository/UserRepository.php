<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
* @implements PasswordUpgraderInterface<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Every user with its assigned roles, in one query, sorted by email.
     *
     * @return list<User>
     */
    public function findAllWithRoles(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.assignedRoles', 'ur')
            ->addSelect('ur')
            ->leftJoin('ur.role', 'r')
            ->addSelect('r')
            ->orderBy('u.email')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a user has this email, compared as the unique index of the column does (case-insensitive collation).
     */
    public function emailExists(string $email): bool
    {
        return $this->count(['email' => $email]) > 0;
    }

    /**
     * Saves a new or changed user with its assigned roles.
     *
     * @throws UniqueConstraintViolationException when another user got the same email in the meantime
     */
    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
