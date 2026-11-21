<?php

namespace App\PerfectworldBundle\Service\PullIcon;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;

/**
 * NTE pull icons from the NTE_Assets datamine (raw.githubusercontent.com at the catalog commit).
 *
 * Only reads the stored catalog: it never triggers a build, so a missing catalog simply yields no icon.
 */
class NteAssetsIconResolver implements PullItemIconResolverInterface
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
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;
        if (!is_a($serviceClass, NevernessToEvernessService::class, true)) {
            return null;
        }

        $itemId = $query->itemId !== null ? trim($query->itemId) : '';
        if ($itemId === '') {
            return null;
        }

        return $this->catalog->getIconUrl($itemId);
    }
}
