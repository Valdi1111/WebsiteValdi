<?php

namespace App\PerfectworldBundle\Service\PullIcon;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;

/**
 * NTE pull icons and full artworks from the NTE_Assets datamine (raw.githubusercontent.com at the catalog commit).
 *
 * Only reads the stored catalog: it never triggers a build, so a missing catalog simply yields no icon and
 * no artwork.
 */
class NteAssetsIconResolver implements PullItemPreviewResolverInterface
{
    public function __construct(
        private readonly NteCatalog $catalog,
    ) {
    }

    public static function getSupportedGameServices(): array
    {
        return [
            NevernessToEvernessService::class => 100,
        ];
    }

    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $itemId = $this->resolveItemId($gameService, $query);

        return $itemId !== null ? $this->catalog->getIconUrl($itemId) : null;
    }

    /**
     * Gacha splash or outfit art of a character, 1024px Arc, illustration of a cosmetic (see NteCatalogBuilder).
     */
    public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $itemId = $this->resolveItemId($gameService, $query);

        return $itemId !== null ? $this->catalog->getPreviewUrl($itemId) : null;
    }

    /**
     * Item id of an NTE query, null for another game or a query without id.
     *
     * @param class-string|object $gameService
     */
    private function resolveItemId(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;
        if (!is_a($serviceClass, NevernessToEvernessService::class, true)) {
            return null;
        }

        $itemId = $query->itemId !== null ? trim($query->itemId) : '';

        return $itemId !== '' ? $itemId : null;
    }
}
