<?php

namespace App\CoreBundle\Model\Game;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('core.pull_item_icon_resolver')]
interface PullItemIconResolverInterface
{
    /**
     * Map of supported game service FQCNs to their priority
     * (higher value = tried first; useful for multi-source fallback).
     *
     * Example: [GenshinImpactService::class => 100, HonkaiStarRailService::class => 100]
     *
     * @return array<class-string, int>
     */
    public static function getSupportedGameServices(): array;

    /**
     * Given the game service (FQCN or instance) and the item's lookup data,
     * returns the source image URL on the external CDN, or null if unsupported/not found.
     *
     * @param class-string|object $gameService
     */
    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string;
}
