<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\Token;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Token>
 *
 * @method Token|null find($id, $lockMode = null, $lockVersion = null)
 * @method Token|null findOneBy(array $criteria, array $orderBy = null)
 * @method Token[]    findAll()
 * @method Token[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Token::class);
    }

    public function getUserFromToken(Token $token): UserInterface
    {
        $user = $this->getEntityManager()
            ->getRepository($token->getClass())
            ->findOneBy(['email' => $token->getUsername()]);
        if ($user instanceof UserInterface) {
            return $user;
        }
        throw new UserNotFoundException("Invalid user.");
    }

    public function getUserTokens(UserInterface $user): array
    {
        return $this->findBy([
            'class' => get_class($user),
            'username' => $user->getUserIdentifier()
        ]);
    }

}
