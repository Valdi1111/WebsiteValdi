<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Model\Profile\ApiTokenView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * API tokens. Only valueOfUser() reads the encrypted value (Token::$accessToken): the login and the other operations
 * select the plain columns, so that a missing or wrong DATABASE_ENCRYPTION_KEY breaks only showing a value.
 * The *OfUser() methods find nothing for a token of another user.
 *
 * @extends ServiceEntityRepository<Token>
 */
class TokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Token::class);
    }

    /**
     * What logging in with a token value needs, found by its hash.
     *
     * @return array{id: int, valid: bool, username: string}|null
     */
    public function findCredentialsByAccessToken(#[\SensitiveParameter] string $accessToken): ?array
    {
        $row = $this->createQueryBuilder('t')
            ->select('t.id', 't.valid', 't.username')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', Token::hashAccessToken($accessToken))
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : ['id' => (int) $row['id'], 'valid' => (bool) $row['valid'], 'username' => (string) $row['username']];
    }

    /**
     * @return list<ApiTokenView> without values, oldest first
     */
    public function findViewsOfUser(UserInterface $user): array
    {
        return $this->ofUser($this->createQueryBuilder('t'), $user)
            ->select(sprintf('NEW %s(t.id, t.description, t.valid)', ApiTokenView::class))
            ->orderBy('t.id')
            ->getQuery()
            ->getResult();
    }

    public function findViewOfUser(int $id, UserInterface $user): ?ApiTokenView
    {
        return $this->ofUser($this->createQueryBuilder('t'), $user)
            ->select(sprintf('NEW %s(t.id, t.description, t.valid)', ApiTokenView::class))
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The decrypted value of a token of the user.
     */
    public function findValueOfUser(int $id, UserInterface $user): ?string
    {
        $row = $this->ofUser($this->createQueryBuilder('t'), $user)
            ->select('t.accessToken')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['accessToken'] ?? null;
    }

    /**
     * Changes the given fields of a token of the user.
     *
     * @param array{description?: string, valid?: bool} $changes
     *
     * @return bool false when the user has no such token
     */
    public function updateOfUser(int $id, UserInterface $user, array $changes): bool
    {
        if (!$this->hasOfUser($id, $user)) {
            return false;
        }
        if ($changes === []) {
            return true;
        }

        $builder = $this->ofUser($this->createQueryBuilder('t'), $user)
            ->update()
            ->andWhere('t.id = :id')
            ->setParameter('id', $id);
        foreach ($changes as $field => $value) {
            $builder->set('t.'.$field, ':'.$field)->setParameter($field, $value);
        }
        $builder->getQuery()->execute();

        return true;
    }

    /**
     * @return bool false when the user has no such token
     */
    public function deleteOfUser(int $id, UserInterface $user): bool
    {
        return $this->ofUser($this->createQueryBuilder('t'), $user)
            ->delete()
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute() > 0;
    }

    public function save(Token $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    /**
     * Whether the user has this token; unlike the affected rows of an UPDATE, true also when nothing changes.
     */
    private function hasOfUser(int $id, UserInterface $user): bool
    {
        return $this->ofUser($this->createQueryBuilder('t'), $user)
            ->select('COUNT(t.id)')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function ofUser(QueryBuilder $builder, UserInterface $user): QueryBuilder
    {
        return $builder
            ->where('t.class = :class')
            ->andWhere('t.username = :username')
            ->setParameter('class', $user::class)
            ->setParameter('username', $user->getUserIdentifier());
    }
}
