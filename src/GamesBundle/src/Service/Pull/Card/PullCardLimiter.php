<?php

namespace App\GamesBundle\Service\Pull\Card;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Exception\TooManyPullCardsException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits the shareable pulls cards per user, wherever they are asked: each one keeps a headless browser busy.
 */
class PullCardLimiter
{
    public function __construct(
        #[Target('games_pull_card')]
        private readonly RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    /**
     * Counts one card; call it before building it.
     *
     * @throws TooManyPullCardsException when the user asked for too many cards
     */
    public function consume(User $user): void
    {
        $limit = $this->limiterFactory->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyPullCardsException($limit->getRetryAfter());
        }
    }
}
