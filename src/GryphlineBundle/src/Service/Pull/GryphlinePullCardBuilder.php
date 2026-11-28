<?php

namespace App\GryphlineBundle\Service\Pull;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardBuilderInterface;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Content of the shareable pulls card of a Gryphline game profile: one banner, or all the banners with pulls.
 */
class GryphlinePullCardBuilder implements PullCardBuilderInterface
{
    /** S pulls shown on the card of a single banner (with the "+N older" cell, four rows of eight) */
    public const int BANNER_LIMIT = 31;
    /** S pulls shown per banner on the card of the whole profile (two rows of eight) */
    public const int PROFILE_LIMIT = 15;

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameBiz,
        private readonly GryphlinePullRepository $pullRepo,
        private readonly GryphlinePullStatsCalculator $statsCalculator,
        private readonly PullCardSectionFactory $sectionFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getProviderKey(): string
    {
        return 'gryphline';
    }

    public function supports(GameProfileInterface $profile): bool
    {
        return $profile instanceof GryphlineGameProfile && $this->findGameService($profile) !== null;
    }

    public function getBanners(GameProfileInterface $profile): array
    {
        $gameService = $profile instanceof GryphlineGameProfile ? $this->findGameService($profile) : null;
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
        if (!$profile instanceof GryphlineGameProfile) {
            throw new \InvalidArgumentException('Not a Gryphline game profile.');
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
    public function build(GryphlineGameProfile $profile, ?string $bannerKey = null, bool $showAll = false, bool $showPlayer = false): PullCard
    {
        $gameService = $this->findGameService($profile) ?? throw new \InvalidArgumentException('This game profile does not support pull tracking.');

        $bannerGroups = $gameService->getGachaConfig()->getBannerGroups('categories');
        $rarityConfig = $gameService->getRarityConfig();

        if ($bannerKey !== null) {
            [$bannerGroup, $rows] = $this->findBanner($profile, $bannerGroups, $bannerKey);
            $summary = $this->statsCalculator->compute($rows, $bannerGroup, $rarityConfig)['summary'];
            $title = $bannerGroup['name'];
            $sections = [$this->sectionFactory->fromSummary($gameService, $summary, $showAll ? null : self::BANNER_LIMIT)];
        } else {
            $title = 'All banners';
            $sections = [];
            // One query for the whole profile, like the overview
            $groupedRows = $this->statsCalculator->groupRowsByBanner($this->pullRepo->findProfileRows($profile), $bannerGroups);
            foreach ($groupedRows as ['group' => $bannerGroup, 'rows' => $rows]) {
                $summary = $this->statsCalculator->compute($rows, $bannerGroup, $rarityConfig)['summary'];
                if (($summary['total_pulls'] ?? 0) > 0) {
                    $sections[] = $this->sectionFactory->fromSummary($gameService, $summary, $showAll ? null : self::PROFILE_LIMIT);
                }
            }
        }

        return new PullCard(
            gameName: $gameService->getGameName(),
            title: $title,
            sLabel: $rarityConfig->getS()->getLabel(),
            pullLabel: $rarityConfig->getPullLabel(),
            sections: $sections,
            generatedAt: $this->clock->now(),
            playerName: $showPlayer ? $profile->getNickname() : null,
            playerUid: $showPlayer ? $profile->getGameUid() : null,
        );
    }

    /**
     * The banner group of a key and its rows, oldest first. A pool type missing from the config is a banner of its own
     * (the overview lists it, so the card can be asked for it): it is found among the groups of the profile.
     *
     * @param array<array-key, array<string, mixed>> $bannerGroups configured groups
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException when the profile has no banner with this key
     */
    private function findBanner(GryphlineGameProfile $profile, array $bannerGroups, string $bannerKey): array
    {
        if (isset($bannerGroups[$bannerKey])) {
            return [$bannerGroups[$bannerKey], $this->pullRepo->findBannerRows($profile, $bannerGroups[$bannerKey]['categories'])];
        }

        foreach ($this->statsCalculator->groupRowsByBanner($this->pullRepo->findProfileRows($profile), $bannerGroups) as ['group' => $group, 'rows' => $rows]) {
            if ((string) $group['key'] === $bannerKey) {
                return [$group, $rows];
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown banner "%s".', $bannerKey));
    }

    private function findGameService(GryphlineGameProfile $profile): (GameInterface&HasAutoPullImportInterface)|null
    {
        $gameBiz = $profile->getGameBiz();
        $gameService = $gameBiz !== null && $this->locatorByGameBiz->has($gameBiz) ? $this->locatorByGameBiz->get($gameBiz) : null;

        return $gameService instanceof GameInterface && $gameService instanceof HasAutoPullImportInterface ? $gameService : null;
    }
}
