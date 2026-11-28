<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardBuilderInterface;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Content of the shareable pulls card of a Perfect World game profile: one banner, or all the banners with pulls.
 */
class PerfectworldPullCardBuilder implements PullCardBuilderInterface
{
    /** S pulls shown on the card of a single banner (with the "+N older" cell, four rows of eight) */
    public const int BANNER_LIMIT = 31;
    /** S pulls shown per banner on the card of the whole profile (two rows of eight) */
    public const int PROFILE_LIMIT = 15;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        private readonly PerfectworldPullRepository $pullRepository,
        private readonly PerfectworldPullStatsCalculator $statsCalculator,
        private readonly NteCatalog $catalog,
        private readonly PullCardSectionFactory $sectionFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getProviderKey(): string
    {
        return 'perfectworld';
    }

    public function supports(GameProfileInterface $profile): bool
    {
        return $profile instanceof PerfectworldGameProfile && $this->findGameService($profile) !== null;
    }

    public function getBanners(GameProfileInterface $profile): array
    {
        $gameService = $profile instanceof PerfectworldGameProfile ? $this->findGameService($profile) : null;
        if ($gameService === null) {
            return [];
        }

        $banners = [];
        foreach ($gameService->getGachaConfig()->getBannerGroups('categories') as $key => $group) {
            $banners[] = ['key' => (string) $key, 'name' => (string) $group['name']];
        }

        return $banners;
    }

    public function buildCard(GameProfileInterface $profile, ?string $bannerKey = null, bool $showAll = false, bool $showPlayer = false): PullCard
    {
        if (!$profile instanceof PerfectworldGameProfile) {
            throw new \InvalidArgumentException('Not a Perfect World game profile.');
        }

        return $this->build($profile, $bannerKey, $showAll, $showPlayer);
    }

    /**
     * @param ?string $bannerKey  the banner group of the card, null for every banner with pulls
     * @param bool    $showAll    every S pull instead of the newest ones (the card grows with the history)
     * @param bool    $showPlayer whether the nickname and the UID appear on the card
     *
     * @throws \InvalidArgumentException when the game has no pull tracker or the banner is unknown
     */
    public function build(PerfectworldGameProfile $profile, ?string $bannerKey = null, bool $showAll = false, bool $showPlayer = false): PullCard
    {
        $gameService = $this->findGameService($profile) ?? throw new \InvalidArgumentException('This game profile does not support pull tracking.');

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups('categories');
        if ($bannerKey !== null && !isset($bannerGroups[$bannerKey])) {
            throw new \InvalidArgumentException(sprintf('Unknown banner "%s".', $bannerKey));
        }

        $sections = [];
        foreach ($bannerKey !== null ? [$bannerKey => $bannerGroups[$bannerKey]] : $bannerGroups as $key => $bannerGroup) {
            $computed = $this->statsCalculator->compute(
                $this->pullRepository->findBannerRows($profile, (string) $key),
                $bannerGroup,
                $gameService->getBannerPullLabels((string) $key),
                $this->catalog->findPoolName(...),
            );
            if ($bannerKey === null && ($computed['summary']['total_pulls'] ?? 0) === 0) {
                continue;
            }

            $limit = $showAll ? null : ($bannerKey !== null ? self::BANNER_LIMIT : self::PROFILE_LIMIT);
            // recent_s_pulls is capped: s_pulls holds them all
            $sections[] = $this->sectionFactory->fromSummary($gameService, $computed['summary'], $limit, $computed['s_pulls']);
        }

        $rarityConfig = $gameService->getRarityConfig();

        return new PullCard(
            gameName: $gameService->getGameName(),
            title: $bannerKey !== null ? $bannerGroups[$bannerKey]['name'] : 'All banners',
            sLabel: $rarityConfig->getS()->getLabel(),
            pullLabel: $rarityConfig->getPullLabel(),
            sections: $sections,
            generatedAt: $this->clock->now(),
            playerName: $showPlayer ? $profile->getNickname() : null,
            playerUid: $showPlayer ? $profile->getGameUid() : null,
        );
    }

    private function findGameService(PerfectworldGameProfile $profile): (GameInterface&HasPullTrackerInterface)|null
    {
        $gameId = (int) $profile->getGameId();
        $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;

        return $gameService instanceof GameInterface && $gameService instanceof HasPullTrackerInterface ? $gameService : null;
    }
}
