<?php

namespace App\CoreBundle\Repository;

use App\CoreBundle\Entity\ResetPasswordRequest;
use App\CoreBundle\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * Storage of the reset password links for symfonycasts/reset-password-bundle (config/packages/reset_password.yaml).
 *
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 *
 * @implements ResetPasswordRequestRepositoryInterface<User>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    /** @use ResetPasswordRequestRepositoryTrait<User> */
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    /**
     * @param User $user
     */
    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }
}
