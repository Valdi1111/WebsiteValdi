<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PullItemIconUrlBuilder
{
    public const string FILTER_NAME = 'games_pull_item_icon';

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
        if (!$this->resolverManager->supportsGameService($gameService)) {
            return null;
        }

        $gameBiz = $this->resolverManager->getGameBizForService($gameService);
        if ($gameBiz === null) {
            return null;
        }

        $path = $this->pathCodec->encode($gameBiz, $query);
        if ($path === null) {
            return null;
        }

        return $this->cacheManager->getBrowserPath($path, self::FILTER_NAME);
    }

    /**
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
            $row['icon_url'] = $this->buildUrl($gameService, PullItemIconQuery::fromPullRow($row));
        }
        unset($row);

        return $rows;
    }
}
