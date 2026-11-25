<?php

namespace App\GamesBundle\Model\Profile;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Entry point of a game provider bundle into the cross-provider layer (GameProfileRegistry): its games, the profiles
 * of a user, and what reading a profile needs. One implementation per provider bundle, usually extending
 * AbstractGameProfileProvider.
 */
#[AutoconfigureTag(self::TAG)]
interface GameProfileProviderInterface
{
    public const string TAG = 'games.profile_provider';

    /**
     * Provider key, as in games.providers (hoyoverse, gryphline, perfectworld).
     */
    public function getKey(): string;

    /**
     * @return class-string<GameProfileInterface> the profile entity of the provider
     */
    public function getProfileClass(): string;

    /**
     * @return array<string, GameDescriptor> every game with a service in the provider, by game biz
     */
    public function getGames(): array;

    /**
     * Game of the profile, or null when the provider has no service for it.
     */
    public function describe(GameProfileInterface $profile): ?GameDescriptor;

    /**
     * @return list<GameProfileInterface> active profiles of the user's accounts, by account id then profile id
     */
    public function findProfilesOf(User $user): array;

    /**
     * Profile of the user with this id, active or not; null when it does not exist or belongs to another user.
     */
    public function findProfileOf(User $user, int $profileId): ?GameProfileInterface;

    /**
     * @throws \RuntimeException when the game of the profile has no service, or the account cannot be read
     */
    public function createContext(GameProfileInterface $profile, bool $fresh = false): GameProfileContext;

    public function getAccountHealth(GameAccountInterface $account): AccountHealth;
}
