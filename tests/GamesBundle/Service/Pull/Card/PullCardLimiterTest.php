<?php

namespace App\Tests\GamesBundle\Service\Pull\Card;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Exception\TooManyPullCardsException;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class PullCardLimiterTest extends TestCase
{
    private PullCardLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new PullCardLimiter(new RateLimiterFactory([
            'id' => 'games_pull_card',
            'policy' => 'sliding_window',
            'limit' => 2,
            'interval' => '10 minutes',
        ], new InMemoryStorage()));
    }

    public function testRefusesTheCardsBeyondTheLimit(): void
    {
        $user = self::user(1);

        $this->consumeAccepted($user, 2);

        try {
            $this->limiter->consume($user);
            $this->fail('The third card must be refused.');
        } catch (TooManyPullCardsException $e) {
            $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $e->getStatusCode());
            $this->assertSame(TooManyPullCardsException::MESSAGE, $e->getMessage());
            $this->assertGreaterThan(new \DateTimeImmutable('-1 second'), $e->retryAfter);

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
            $this->assertIsString($retryAfter);
            $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter);
            $this->assertGreaterThanOrEqual(1, (int) $retryAfter);
            $this->assertLessThanOrEqual(600, (int) $retryAfter, 'Within the 10 minutes window');
        }
    }

    public function testEachUserHasABudgetOfItsOwn(): void
    {
        $first = self::user(1);
        $second = self::user(2);

        $this->consumeAccepted($first, 2);
        // The first user is out of cards, the second one still has them all
        $this->consumeAccepted($second, 2);

        foreach ([$first, $second] as $user) {
            try {
                $this->limiter->consume($user);
                $this->fail(sprintf('User %d is out of cards.', $user->getId()));
            } catch (TooManyPullCardsException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function consumeAccepted(User $user, int $times): void
    {
        for ($i = 1; $i <= $times; ++$i) {
            try {
                $this->limiter->consume($user);
                $this->addToAssertionCount(1);
            } catch (TooManyPullCardsException) {
                $this->fail(sprintf('Card %d of user %d must be accepted.', $i, $user->getId()));
            }
        }
    }

    private static function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
