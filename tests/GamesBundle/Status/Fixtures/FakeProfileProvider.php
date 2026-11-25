<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Service\Profile\AbstractGameProfileProvider;

/**
 * Provider over profiles held in memory, with one game service per game biz.
 */
class FakeProfileProvider extends AbstractGameProfileProvider
{
    /** @var list<GameProfileInterface> */
    public array $profiles = [];

    /** @var array<string, object> game biz => game service */
    public array $services = [];

    public ?AccountHealth $health = null;

    public ?\Throwable $contextError = null;

    /** @var array<string, array{0: string, 1?: list<string>}> */
    public array $slugs = ['fake_game' => ['fg', ['fake']]];

    /**
     * @param class-string<GameProfileInterface> $profileClass
     */
    public function __construct(
        private readonly string $key = 'fake',
        private readonly string $profileClass = FakeGameProfile::class,
        public bool $withAccountData = true,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getProfileClass(): string
    {
        return $this->profileClass;
    }

    public function findProfilesOf(User $user): array
    {
        return self::activeInOrder(array_filter($this->profiles, static fn (GameProfileInterface $profile): bool => self::isOwnedBy($profile, $user)));
    }

    protected function createGameDescriptors(): iterable
    {
        foreach ($this->services as $gameBiz => $service) {
            yield $this->describeGame($gameBiz, 'Game ' . $gameBiz, null, $this->slugs);
        }
    }

    protected function findProfile(int $profileId): ?GameProfileInterface
    {
        foreach ($this->profiles as $profile) {
            if ($profile->getId() === $profileId) {
                return $profile;
            }
        }

        return null;
    }

    protected function findGameService(GameProfileInterface $profile): ?object
    {
        if ($this->contextError !== null) {
            throw $this->contextError;
        }

        return $this->services[$profile->getGameBiz()] ?? null;
    }

    protected function createAccountData(GameProfileInterface $profile, object $gameService): ?RuntimeAccountDataInterface
    {
        if (!$this->withAccountData) {
            return null;
        }

        return new class($profile) implements RuntimeAccountDataInterface {
            public function __construct(private readonly GameProfileInterface $profile)
            {
            }

            public function getGameProfile(): GameProfileInterface
            {
                return $this->profile;
            }

            public function getAccount(): GameAccountInterface
            {
                return $this->profile->getAccount();
            }

            public function getUser(): ?User
            {
                return $this->profile->getAccount()?->getUser();
            }
        };
    }

    public function getAccountHealth(GameAccountInterface $account): AccountHealth
    {
        return $this->health ?? AccountHealth::ok();
    }
}
