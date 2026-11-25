<?php

namespace App\GryphlineBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Service\Profile\AbstractGameProfileProvider;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Gryphline (SKPort) adapter of the cross-provider profile layer: game services by game biz, profiles of the user's
 * accounts, and runtime account data whose SKPort credentials the game service resolves lazily.
 */
class GryphlineProfileProvider extends AbstractGameProfileProvider
{
    /**
     * Game biz => [slug, aliases].
     */
    private const array SLUGS = [
        'endfield' => ['ake', ['endfield', 'arknights-endfield']],
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly GryphlineAccountRepository $accountRepository,
        private readonly GryphlineGameProfileRepository $gameProfileRepository,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameBiz,
    ) {
    }

    public function getKey(): string
    {
        return 'gryphline';
    }

    public function getProfileClass(): string
    {
        return GryphlineGameProfile::class;
    }

    public function findProfilesOf(User $user): array
    {
        $profiles = [];
        foreach ($this->accountRepository->findBy(['user' => $user], ['id' => 'ASC']) as $account) {
            foreach ($account->getGameProfiles() as $profile) {
                $profiles[] = $profile;
            }
        }

        return self::activeInOrder($profiles);
    }

    /**
     * An expired SKPort session is routine (the game service renews it from the account token on the next call):
     * only a failed renewal needs the user.
     */
    public function getAccountHealth(GameAccountInterface $account): AccountHealth
    {
        if ($account instanceof GryphlineAccount && $account->isTokenRefreshFailed() === true) {
            return AccountHealth::problem('The SKPort session could not be refreshed: log in again on the site.');
        }

        return AccountHealth::ok();
    }

    protected function createGameDescriptors(): iterable
    {
        /** @var GameInterface $service */
        foreach ($this->locatorByGameBiz as $gameBiz => $service) {
            yield $this->describeGame((string) $gameBiz, $service->getGameName(), $service->getIcon(), self::SLUGS);
        }
    }

    protected function findProfile(int $profileId): ?GryphlineGameProfile
    {
        return $this->gameProfileRepository->find($profileId);
    }

    protected function findGameService(GameProfileInterface $profile): ?GameInterface
    {
        $gameBiz = $profile->getGameBiz();

        return $gameBiz !== null && $this->locatorByGameBiz->has($gameBiz) ? $this->locatorByGameBiz->get($gameBiz) : null;
    }

    /**
     * Same runtime data as the profile task handlers: the game service resolves the SKPort credentials on first use.
     */
    protected function createAccountData(GameProfileInterface $profile, object $gameService): RuntimeAccountDataInterface
    {
        if (!$profile instanceof GryphlineGameProfile) {
            throw new \RuntimeException(sprintf('Expected a %s, got %s.', GryphlineGameProfile::class, $profile::class));
        }

        return new RuntimeAccountData()->setGameProfile($profile);
    }
}
