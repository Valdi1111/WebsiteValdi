<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserAvatar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Profile images, by user (their primary key is the user's id) or by their random name.
 * The lookups that do not need the image select only the columns they use: an entity always loads the BLOB.
 *
 * @extends ServiceEntityRepository<UserAvatar>
 */
class UserAvatarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAvatar::class);
    }

    public function findOneByUser(User $user): ?UserAvatar
    {
        return $this->find($user->getId());
    }

    /**
     * @return string|null name of the user's avatar (for its URL), without loading the image
     */
    public function findNameByUser(User $user): ?string
    {
        $row = $this->createQueryBuilder('a')
            ->select('a.name')
            ->where('a.user = :user')
            ->setParameter('user', $user->getId())
            ->getQuery()
            ->getOneOrNullResult();

        return $row['name'] ?? null;
    }

    /**
     * @return string|null the normalized image of that name (UserAvatarLoader)
     */
    public function findContentByName(string $name): ?string
    {
        $row = $this->createQueryBuilder('a')
            ->select('a.content')
            ->where('a.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
        if ($row === null) {
            return null;
        }

        $content = $row['content'];

        return is_resource($content) ? (string) stream_get_contents($content) : (string) $content;
    }

    public function save(UserAvatar $avatar): void
    {
        $this->getEntityManager()->persist($avatar);
        $this->getEntityManager()->flush();
    }

    public function delete(UserAvatar $avatar): void
    {
        $this->getEntityManager()->remove($avatar);
        $this->getEntityManager()->flush();
    }
}
