<?php

namespace App\GamesBundle\Model\Game;

/**
 * An icon resolver that also knows the full artwork of an item (gacha splash, portrait, weapon art),
 * shown by the click-to-zoom preview of the pulls tracker.
 *
 * Optional: resolvers implementing only PullItemIconResolverInterface have no artwork, and their items
 * preview the icon source.
 */
interface PullItemPreviewResolverInterface extends PullItemIconResolverInterface
{
    /**
     * Given the game service (FQCN or instance) and the item's lookup data,
     * returns the source URL of the full artwork on the external CDN, or null when the item has none.
     *
     * A null (or a URL answering 404) makes the preview fall back to the icon source; any other failure
     * leaves the preview unresolved, so that it is retried instead of caching the icon.
     *
     * @param class-string|object $gameService
     */
    public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string;
}
