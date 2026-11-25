<?php

namespace App\GamesBundle\Service\Profile;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\GameProfileProviderInterface;
use App\GamesBundle\Model\Profile\GameProfileRef;

/**
 * Shared part of the provider adapters: game descriptors by game biz, ownership by user id, profile order and the
 * context. A provider supplies its games, how to find a profile and its game service, and its runtime account data.
 */
abstract class AbstractGameProfileProvider implements GameProfileProviderInterface
{
    /** @var array<string, GameDescriptor>|null */
    private ?array $games = null;

    /**
     * @return iterable<GameDescriptor> one per game service of the provider
     */
    abstract protected function createGameDescriptors(): iterable;

    /**
     * Profile with this id, whoever owns it; null when it does not exist.
     */
    abstract protected function findProfile(int $profileId): ?GameProfileInterface;

    /**
     * Game service of the profile, or null when the provider has none for its game.
     */
    abstract protected function findGameService(GameProfileInterface $profile): ?object;

    /**
     * Runtime account data the game service reads the API with, or null for a provider without API data.
     */
    abstract protected function createAccountData(GameProfileInterface $profile, object $gameService): ?RuntimeAccountDataInterface;

    public function getGames(): array
    {
        if ($this->games === null) {
            $this->games = [];
            foreach ($this->createGameDescriptors() as $game) {
                $this->games[$game->gameBiz] = $game;
            }
        }

        return $this->games;
    }

    public function describe(GameProfileInterface $profile): ?GameDescriptor
    {
        $gameBiz = $profile->getGameBiz();

        return $gameBiz !== null ? $this->getGames()[$gameBiz] ?? null : null;
    }

    public function findProfileOf(User $user, int $profileId): ?GameProfileInterface
    {
        $profile = $this->findProfile($profileId);

        return $profile !== null && self::isOwnedBy($profile, $user) ? $profile : null;
    }

    public function createContext(GameProfileInterface $profile, bool $fresh = false): GameProfileContext
    {
        $game = $this->describe($profile);
        $gameService = $this->findGameService($profile);
        if ($game === null || $gameService === null || $profile->getId() === null) {
            throw new \RuntimeException(sprintf('No %s game service for game "%s".', $this->getKey(), $profile->getGameBiz() ?? '?'));
        }

        return new GameProfileContext(
            profile: $profile,
            ref: new GameProfileRef($this->getKey(), $profile->getId()),
            game: $game,
            gameService: $gameService,
            accountData: $this->createAccountData($profile, $gameService),
            fresh: $fresh,
        );
    }

    /**
     * Descriptor of a game, with the slug and aliases of the provider's map or, for a game missing from it, a slug
     * derived from its name: a new game service still shows up.
     *
     * @param array<string, array{0: string, 1?: list<string>}> $slugs game biz => [slug, aliases]
     */
    protected function describeGame(string $gameBiz, string $name, ?string $iconUrl, array $slugs): GameDescriptor
    {
        return new GameDescriptor(
            provider: $this->getKey(),
            gameBiz: $gameBiz,
            slug: $slugs[$gameBiz][0] ?? GameDescriptor::slugify($name),
            name: $name,
            iconUrl: $iconUrl,
            aliases: $slugs[$gameBiz][1] ?? [],
        );
    }

    /**
     * Owners are compared by id, as GameAccountOwnerVoter does: the user of a chat request is not the instance
     * Doctrine loaded with the account.
     */
    protected static function isOwnedBy(GameProfileInterface $profile, User $user): bool
    {
        $owner = $profile->getAccount()?->getUser();

        return $owner !== null && $owner->getId() !== null && $owner->getId() === $user->getId();
    }

    /**
     * @param iterable<GameProfileInterface> $profiles
     * @return list<GameProfileInterface> the active ones, by account id then profile id
     */
    protected static function activeInOrder(iterable $profiles): array
    {
        $active = [];
        foreach ($profiles as $profile) {
            if ($profile->isActive()) {
                $active[] = $profile;
            }
        }

        usort($active, static fn (GameProfileInterface $a, GameProfileInterface $b): int =>
            [$a->getAccount()?->getId() ?? 0, $a->getId() ?? 0] <=> [$b->getAccount()?->getId() ?? 0, $b->getId() ?? 0]);

        return $active;
    }
}
