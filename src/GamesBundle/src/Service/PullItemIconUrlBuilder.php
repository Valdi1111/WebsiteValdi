<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Pull\PullOrigin;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

class PullItemIconUrlBuilder
{
    public const string FILTER_NAME = 'games_pull_item_icon';
    public const string PREVIEW_FILTER_NAME = 'games_pull_item_preview';

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly PullItemIconPathCodec $pathCodec,
        private readonly PullItemIconResolverManager $resolverManager,
    ) {
    }

    /**
     * @param class-string|object $gameService
     */
    public function buildUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $path = $this->buildPath($gameService, $query);

        return $path !== null ? $this->cacheManager->getBrowserPath($path, self::FILTER_NAME) : null;
    }

    /**
     * Adds icon_url (the round thumbnail) and preview_url (the click-to-zoom artwork) to pull-shaped rows;
     * both are null when the row has no item to look up.
     *
     * @param class-string|object $gameService
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrichPullRows(string|object $gameService, array $rows): array
    {
        if (!$this->resolverManager->supportsGameService($gameService)) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $path = $this->buildRowPath($gameService, $row);
            $row['icon_url'] = $path !== null ? $this->cacheManager->getBrowserPath($path, self::FILTER_NAME) : null;
            $row['preview_url'] = $path !== null ? $this->cacheManager->getBrowserPath($path, self::PREVIEW_FILTER_NAME) : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Path of the item of a pull-shaped row in the filter sets, null when it has no image to look up.
     *
     * @param class-string|object $gameService
     * @param array<string, mixed> $row
     */
    public function buildRowPath(string|object $gameService, array $row): ?string
    {
        return self::hasItem($row) ? $this->buildPath($gameService, PullItemIconQuery::fromPullRow($row)) : null;
    }

    /**
     * Filler rows are unknown pulls, and a bonus entry without an item (e.g. the Endfield Headhunting Dossier) has
     * nothing to look up by name: neither has an image.
     *
     * @param array<string, mixed> $row
     */
    private static function hasItem(array $row): bool
    {
        if (($row['origin'] ?? null) === PullOrigin::Filler->value) {
            return false;
        }

        return ($row['bonus_label'] ?? null) === null || ($row['item_id'] ?? null) !== null;
    }

    /**
     * @param class-string|object $gameService
     */
    private function buildPath(string|object $gameService, PullItemIconQuery $query): ?string
    {
        if (!$this->resolverManager->supportsGameService($gameService)) {
            return null;
        }

        $gameBiz = $this->resolverManager->getGameBizForService($gameService);
        if ($gameBiz === null) {
            return null;
        }

        return $this->pathCodec->encode($gameBiz, $query);
    }
}
