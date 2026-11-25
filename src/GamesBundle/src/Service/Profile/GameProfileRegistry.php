<?php

namespace App\GamesBundle\Service\Profile;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileProviderInterface;
use App\GamesBundle\Model\Profile\GameProfileRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Game profiles across the provider bundles, for callers that have a user but no security token (chat commands,
 * scheduled summaries).
 *
 * A provider is reachable only when it is listed in games.providers and the user has its role, assigned or inherited,
 * as for the tabs of the games page; providers keep the configured order. Every lookup by reference goes through the
 * same role check and through the ownership check of the provider.
 */
class GameProfileRegistry
{
    /** @var array<string, GameProfileProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<GameProfileProviderInterface> $providers
     * @param array<string, string>                  $providerRoles provider key => role, in tab order (games.providers)
     */
    public function __construct(
        #[AutowireIterator(GameProfileProviderInterface::TAG)]
        iterable $providers,
        // Lazy: the role hierarchy reads the database when built, which a command shown with --help must not do
        #[Autowire(lazy: true)]
        private readonly UserRoleResolver $roleResolver,
        #[Autowire(param: 'games.providers')]
        private readonly array $providerRoles,
    ) {
        $byKey = [];
        foreach ($providers as $provider) {
            $byKey[$provider->getKey()] = $provider;
        }
        // A provider missing from games.providers has no role to grant it, so it is left out
        foreach (array_keys($this->providerRoles) as $key) {
            if (isset($byKey[$key])) {
                $this->providers[(string) $key] = $byKey[$key];
            }
        }
    }

    /**
     * @return array<string, GameProfileProviderInterface> every provider, in the configured order
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, GameProfileProviderInterface> the providers the user has the role of, in the configured order
     */
    public function getGrantedProviders(User $user): array
    {
        $roles = $this->roleResolver->getReachableRoleNames($user);

        return array_filter(
            $this->providers,
            fn (GameProfileProviderInterface $provider, string $key): bool => in_array($this->providerRoles[$key], $roles, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<GameProfileInterface> active profiles of the user, by provider then as each provider orders them
     */
    public function findProfilesOf(User $user, ?GameDescriptor $game = null): array
    {
        $profiles = [];
        foreach ($this->getGrantedProviders($user) as $key => $provider) {
            if ($game !== null && $game->provider !== $key) {
                continue;
            }
            foreach ($provider->findProfilesOf($user) as $profile) {
                if ($game === null || $profile->getGameBiz() === $game->gameBiz) {
                    $profiles[] = $profile;
                }
            }
        }

        return $profiles;
    }

    /**
     * Profile behind a reference, or null when the provider is unknown or not granted to the user, or the profile
     * does not exist or belongs to another user.
     */
    public function resolve(User $user, GameProfileRef $ref): ?GameProfileInterface
    {
        return ($this->getGrantedProviders($user)[$ref->provider] ?? null)?->findProfileOf($user, $ref->id);
    }

    public function providerOf(GameProfileInterface $profile): ?GameProfileProviderInterface
    {
        foreach ($this->providers as $provider) {
            if (is_a($profile, $provider->getProfileClass())) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException when the profile is not saved or belongs to no registered provider
     */
    public function refOf(GameProfileInterface $profile): GameProfileRef
    {
        $provider = $this->providerOf($profile);
        if ($provider === null || $profile->getId() === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a saved profile of a registered game provider.', $profile::class));
        }

        return new GameProfileRef($provider->getKey(), $profile->getId());
    }

    public function describe(GameProfileInterface $profile): ?GameDescriptor
    {
        return $this->providerOf($profile)?->describe($profile);
    }

    /**
     * @return list<GameDescriptor> the games of every provider, or of the providers granted to $user
     */
    public function getGames(?User $user = null): array
    {
        $games = [];
        foreach ($user !== null ? $this->getGrantedProviders($user) : $this->providers as $provider) {
            array_push($games, ...array_values($provider->getGames()));
        }

        return $games;
    }

    /**
     * Game by slug or alias, ignoring case, among every provider or the providers granted to $user.
     */
    public function findGame(string $name, ?User $user = null): ?GameDescriptor
    {
        foreach ($this->getGames($user) as $game) {
            if ($game->matches($name)) {
                return $game;
            }
        }

        return null;
    }
}
