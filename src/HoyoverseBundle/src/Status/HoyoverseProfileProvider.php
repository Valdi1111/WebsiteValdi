<?php

namespace App\HoyoverseBundle\Status;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Service\Profile\AbstractGameProfileProvider;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * HoYoverse games in the cross-provider layer: one game per service of the hoyoverse.game.biz locator, profiles of
 * the user's HoYoLAB accounts, runtime account data from the parsed account cookie.
 */
class HoyoverseProfileProvider extends AbstractGameProfileProvider
{
    /**
     * Slug and aliases by game biz; a game service missing here gets a slug derived from its name.
     */
    private const array SLUGS = [
        'hk4e_global' => ['gi', ['genshin', 'genshin-impact']],
        'hkrpg_global' => ['hsr', ['starrail', 'star-rail', 'honkai-star-rail']],
        'nap_global' => ['zzz', ['zenless', 'zenless-zone-zero']],
        'bh3_global' => ['hi3', ['bh3', 'honkai-impact-3rd']],
        'nxx_global' => ['tot', ['tears-of-themis']],
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly HoyoverseAccountRepository $accountRepository,
        private readonly HoyoverseGameProfileRepository $gameProfileRepository,
        private readonly HoyolabCookieUtilsService $cookieUtils,
        #[AutowireLocator(services: 'hoyoverse.game.biz', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameBiz,
    ) {
    }

    public function getKey(): string
    {
        return 'hoyoverse';
    }

    public function getProfileClass(): string
    {
        return HoyoverseGameProfile::class;
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

    public function getAccountHealth(GameAccountInterface $account): AccountHealth
    {
        if ($account instanceof HoyoverseAccount && $account->isCookieRefreshFailed() === true) {
            return AccountHealth::problem('The HoYoLAB session could not be refreshed: log in again on the site.');
        }

        return AccountHealth::ok();
    }

    protected function createGameDescriptors(): iterable
    {
        // Keyed by the tag attribute, i.e. the game biz, in the order of the locator
        /** @var GameInterface $gameService */
        foreach ($this->locatorByGameBiz as $gameBiz => $gameService) {
            yield $this->describeGame((string) $gameBiz, $gameService->getGameName(), null, self::SLUGS);
        }
    }

    protected function findProfile(int $profileId): ?HoyoverseGameProfile
    {
        return $this->gameProfileRepository->find($profileId);
    }

    protected function findGameService(GameProfileInterface $profile): ?GameInterface
    {
        $gameBiz = $profile->getGameBiz();

        return $gameBiz !== null && $this->locatorByGameBiz->has($gameBiz) ? $this->locatorByGameBiz->get($gameBiz) : null;
    }

    protected function createAccountData(GameProfileInterface $profile, object $gameService): RuntimeAccountData
    {
        if (!$profile instanceof HoyoverseGameProfile) {
            throw new \RuntimeException(sprintf('Expected a %s, got %s.', HoyoverseGameProfile::class, $profile::class));
        }

        // Named by account only, as in the profile task handlers: the cookie must never reach the logs
        $account = $profile->getAccount();
        $parsedCookie = $this->cookieUtils->tryParseCookie($account?->getCookie())
            ?? throw ConfigurationException::invalidCookie($account?->getId());

        return new RuntimeAccountData()
            ->setParsedCookie($parsedCookie)
            ->setGameProfile($profile);
    }
}
