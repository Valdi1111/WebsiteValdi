<?php

namespace App\PerfectworldBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Service\Profile\AbstractGameProfileProvider;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Perfect World adapter of the cross-provider profile layer: game services by game biz and profiles of the user's
 * accounts. There is no game API data (no notes) yet, so the context has no runtime account data: no section that
 * reads the game API supports these profiles, which still show up in summaries.
 */
class PerfectworldProfileProvider extends AbstractGameProfileProvider
{
    /**
     * Game biz => [slug, aliases].
     */
    private const array SLUGS = [
        'nte' => ['nte', ['neverness-to-everness']],
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly PerfectworldAccountRepository $accountRepository,
        private readonly PerfectworldGameProfileRepository $gameProfileRepository,
        #[AutowireLocator(services: 'perfectworld.game.biz', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameBiz,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getKey(): string
    {
        return 'perfectworld';
    }

    public function getProfileClass(): string
    {
        return PerfectworldGameProfile::class;
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
     * The SDK token has no refresh: once rejected or past its expiry (30 days after login), the user must log in
     * again, as the account card on the site says. The expiry is read at login on a best-effort basis and may be
     * unknown (null), which is not a problem.
     */
    public function getAccountHealth(GameAccountInterface $account): AccountHealth
    {
        if (!$account instanceof PerfectworldAccount) {
            return AccountHealth::ok();
        }

        if ($account->isTokenInvalid() === true) {
            return AccountHealth::problem('The Perfect World login is no longer valid: log in again on the site.');
        }

        $expiresAt = $account->getTokenExpiresAt();
        if ($expiresAt !== null && $expiresAt < $this->clock->now()) {
            return AccountHealth::problem('The Perfect World login has expired: log in again on the site.');
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

    protected function findProfile(int $profileId): ?PerfectworldGameProfile
    {
        return $this->gameProfileRepository->find($profileId);
    }

    protected function findGameService(GameProfileInterface $profile): ?GameInterface
    {
        $gameBiz = $profile->getGameBiz();

        return $gameBiz !== null && $this->locatorByGameBiz->has($gameBiz) ? $this->locatorByGameBiz->get($gameBiz) : null;
    }

    protected function createAccountData(GameProfileInterface $profile, object $gameService): ?RuntimeAccountDataInterface
    {
        return null;
    }
}
