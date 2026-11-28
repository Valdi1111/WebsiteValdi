<?php

namespace App\Tests\GamesBundle\Service\Pull\Card\Fixtures;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardBuilderInterface;

/**
 * Pulls card builder of the "fake" provider (the one of GamesChatWorld): the games with a pull tracker are chosen by
 * game biz, the banners are configurable, and every card asked is recorded. A subclass overrides getProviderKey() for
 * another provider, the key being static.
 */
class FakePullCardBuilder implements PullCardBuilderInterface
{
    public const string GENERATED_AT = '2026-11-27 12:00:00';

    /** @var list<array{key: string, name: string}> */
    public array $banners = [
        ['key' => 'char', 'name' => 'Character Event'],
        ['key' => 'weapon', 'name' => 'Weapon Event'],
        ['key' => '200', 'name' => 'Standard'],
    ];

    /**
     * Banners missing from the configuration but built anyway, as a gacha type of its own with pulls
     *
     * @var array<string, string> key => name
     */
    public array $unconfiguredBanners = [];

    /** @var list<array{profile: GameProfileInterface, bannerKey: ?string, showAll: bool, showPlayer: bool}> */
    public array $calls = [];

    /** Thrown by buildCard() when set, after the call is recorded */
    public ?\Throwable $error = null;

    /**
     * @param list<string> $trackedGames game biz of the games with a pull tracker
     */
    public function __construct(public array $trackedGames = ['full_game'])
    {
    }

    public static function getProviderKey(): string
    {
        return 'fake';
    }

    public function supports(GameProfileInterface $profile): bool
    {
        return in_array($profile->getGameBiz(), $this->trackedGames, true);
    }

    public function getBanners(GameProfileInterface $profile): array
    {
        return $this->supports($profile) ? $this->banners : [];
    }

    public function buildCard(GameProfileInterface $profile, ?string $bannerKey = null, bool $showAll = false, bool $showPlayer = false): PullCard
    {
        $this->calls[] = ['profile' => $profile, 'bannerKey' => $bannerKey, 'showAll' => $showAll, 'showPlayer' => $showPlayer];
        if ($this->error !== null) {
            throw $this->error;
        }

        $title = 'All banners';
        if ($bannerKey !== null) {
            // Like the real builders: an unknown key is refused
            $banner = array_find($this->banners, static fn (array $banner): bool => $banner['key'] === $bannerKey);
            $title = $banner['name'] ?? $this->unconfiguredBanners[$bannerKey]
                ?? throw new \InvalidArgumentException(sprintf('Unknown banner "%s".', $bannerKey));
        }

        return new PullCard(
            gameName: $profile->getGameName() ?? 'Game',
            title: $title,
            sLabel: '5★',
            pullLabel: 'Pulls',
            sections: [],
            generatedAt: new \DateTimeImmutable(self::GENERATED_AT),
            playerName: $showPlayer ? $profile->getNickname() : null,
            playerUid: $showPlayer ? $profile->getGameUid() : null,
        );
    }
}
