<?php

namespace App\GamesBundle\Service\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Exception\TooManyStatusRefreshesException;
use App\GamesBundle\Model\Profile\GameProfileRef;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits the status reads that skip the caches, per user and game profile, wherever they are asked (the site, the
 * bot): each one calls the game APIs again. Reads from the caches are not limited.
 */
class ProfileStatusRefreshLimiter
{
    public function __construct(
        #[Target('games_status_refresh')]
        private readonly RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    /**
     * Counts one fresh read; call it only for a profile already resolved for the user.
     *
     * @throws TooManyStatusRefreshesException when the user refreshed this profile too often
     */
    public function consume(User $user, GameProfileRef $ref): void
    {
        $limit = $this->limiterFactory->create(sprintf('%d:%s', $user->getId(), $ref))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyStatusRefreshesException($limit->getRetryAfter());
        }
    }
}
