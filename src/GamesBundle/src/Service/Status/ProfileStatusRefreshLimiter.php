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
        $limit = $this->limiterFactory->create(self::key($user, $ref))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyStatusRefreshesException($limit->getRetryAfter());
        }
    }

    /**
     * A fresh read of several profiles at once (Refresh of a chat answer): all or none. Every profile is checked first
     * without spending anything, so a refused Refresh costs nothing; then one read is counted for each.
     *
     * @param list<GameProfileRef> $refs
     * @return string|null null when accepted (and counted), else the refusal shown to the user
     */
    public function consumeAll(User $user, array $refs): ?string
    {
        foreach ($refs as $ref) {
            // consume(0) only reports: it is always "accepted", the tokens left tell
            if ($this->limiterFactory->create(self::key($user, $ref))->consume(0)->getRemainingTokens() < 1) {
                return TooManyStatusRefreshesException::MESSAGE;
            }
        }
        foreach ($refs as $ref) {
            $this->limiterFactory->create(self::key($user, $ref))->consume();
        }

        return null;
    }

    private static function key(User $user, GameProfileRef $ref): string
    {
        return sprintf('%d:%s', $user->getId(), $ref);
    }
}
