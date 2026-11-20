<?php

namespace App\HoyoverseBundle\Service\PullIcon;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;

class NanokaIconResolver implements PullItemIconResolverInterface
{
    public function __construct(
        private readonly NanokaCatalogService $catalogService,
    ) {
    }

    public static function getSupportedGameServices(): array
    {
        return [
            ZenlessZoneZeroService::class => 100,
            HonkaiStarRailService::class => 100,
            GenshinImpactService::class => 100,
        ];
    }

    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;

        return match (true) {
            is_a($serviceClass, ZenlessZoneZeroService::class, true) => $this->resolveZzz($query),
            is_a($serviceClass, HonkaiStarRailService::class, true) => $this->resolveHsr($query),
            is_a($serviceClass, GenshinImpactService::class, true) => $this->resolveGenshin($query),
            default => null,
        };
    }

    private function resolveZzz(PullItemIconQuery $query): ?string
    {
        $type = $this->normalizeType($query->itemType);
        $item = $this->catalogService->findZzzItem($query->itemId, $query->name, $type);
        $icon = $item['icon'] ?? null;

        return is_string($icon) && $icon !== ''
            ? sprintf('https://static.nanoka.cc/assets/zzz/%s.webp', $icon)
            : null;
    }

    private function resolveHsr(PullItemIconQuery $query): ?string
    {
        $itemId = $query->itemId !== null ? trim($query->itemId) : '';
        if ($itemId === '' || !ctype_digit($itemId)) {
            return null;
        }

        $type = $this->normalizeType($query->itemType);
        $isWeapon = $type === 'weapon' || ($type === null && strlen($itemId) >= 5 && str_starts_with($itemId, '2'));
        $isChar = $type === 'character' || ($type === null && strlen($itemId) === 4);

        if ($isChar) {
            return sprintf('https://static.nanoka.cc/assets/hsr/avatarroundicon/%s.webp', $itemId);
        }
        if ($isWeapon) {
            return sprintf('https://static.nanoka.cc/assets/hsr/lightconemediumicon/%s.webp', $itemId);
        }

        return null;
    }

    private function resolveGenshin(PullItemIconQuery $query): ?string
    {
        $type = $this->normalizeType($query->itemType);
        $item = $this->catalogService->findGenshinItem($query->itemId, $query->name, $type);
        $icon = $item['icon'] ?? null;

        return is_string($icon) && $icon !== ''
            ? sprintf('https://static.nanoka.cc/assets/gi/%s.webp', $icon)
            : null;
    }

    private function normalizeType(?string $itemType): ?string
    {
        if ($itemType === null || trim($itemType) === '') {
            return null;
        }

        $t = strtolower(trim($itemType));
        if (in_array($t, ['character', 'char', 'agent', 'agents'], true)) {
            return 'character';
        }
        if (in_array($t, ['weapon', 'w-engine', 'wengine', 'w-engines', 'light-cone', 'light cone'], true)) {
            return 'weapon';
        }
        if (in_array($t, ['bangboo', 'bangboos'], true)) {
            return 'bangboo';
        }

        return null;
    }
}
