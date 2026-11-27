<?php

namespace App\HoyoverseBundle\Service\PullIcon;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;

/**
 * Icons and full artworks of Genshin Impact, Honkai: Star Rail and Zenless Zone Zero items on static.nanoka.cc.
 *
 * Artworks: Genshin wish splash art (typically 2048x1024 for characters, 512x1024 for weapons), HSR character
 * gacha card (2048x2048) and light cone figure (904x1260), ZZZ agent portrait (about 2000px tall). ZZZ W-Engines
 * and Bangboos have no full artwork on the CDN, so they have no preview URL and the preview falls back to the icon.
 */
class NanokaIconResolver implements PullItemPreviewResolverInterface
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

    public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;

        return match (true) {
            is_a($serviceClass, ZenlessZoneZeroService::class, true) => $this->resolveZzzPreview($query),
            is_a($serviceClass, HonkaiStarRailService::class, true) => $this->resolveHsrPreview($query),
            is_a($serviceClass, GenshinImpactService::class, true) => $this->resolveGenshinPreview($query),
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
        return match ($this->detectHsrCategory($query)) {
            'character' => sprintf('https://static.nanoka.cc/assets/hsr/avatarroundicon/%s.webp', trim((string) $query->itemId)),
            'weapon' => sprintf('https://static.nanoka.cc/assets/hsr/lightconemediumicon/%s.webp', trim((string) $query->itemId)),
            default => null,
        };
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

    /**
     * Agent portrait: the catalog normalizes agent icons to the round "IconRoleCircle<n>",
     * the portrait is "IconRole<n>". W-Engines have no full artwork, only the icon (400x400 for
     * almost all) and a 256x256 "<icon>Big"; Bangboos only have their 512x512 garage icon.
     */
    private function resolveZzzPreview(PullItemIconQuery $query): ?string
    {
        $item = $this->catalogService->findZzzItem($query->itemId, $query->name, $this->normalizeType($query->itemType));
        $icon = $item['icon'] ?? null;

        if (!is_string($icon) || !preg_match('/^IconRoleCircle(\w+)$/', $icon, $m)) {
            return null;
        }

        return sprintf('https://static.nanoka.cc/assets/zzz/IconRole%s.webp', $m[1]);
    }

    /**
     * Character gacha card and light cone figure, by numeric id like the icons.
     */
    private function resolveHsrPreview(PullItemIconQuery $query): ?string
    {
        return match ($this->detectHsrCategory($query)) {
            'character' => sprintf('https://static.nanoka.cc/assets/hsr/avatardrawcard/%s.webp', trim((string) $query->itemId)),
            'weapon' => sprintf('https://static.nanoka.cc/assets/hsr/lightconemaxfigures/%s.webp', trim((string) $query->itemId)),
            default => null,
        };
    }

    /**
     * Wish splash art, derived from the catalog icon key. Weapons are limited to the five standard
     * weapon classes: the other weapon icons in the catalog (AssaultRifle, Grenade, Shotgun... and the
     * "_{0}" templated ones) have no splash art.
     */
    private function resolveGenshinPreview(PullItemIconQuery $query): ?string
    {
        $item = $this->catalogService->findGenshinItem($query->itemId, $query->name, $this->normalizeType($query->itemType));
        $icon = $item['icon'] ?? null;
        if (!is_string($icon)) {
            return null;
        }

        if (preg_match('/^UI_AvatarIcon_(\w+)$/', $icon, $m)) {
            return sprintf('https://static.nanoka.cc/assets/gi/UI_Gacha_AvatarImg_%s.webp', $m[1]);
        }
        if (preg_match('/^UI_EquipIcon_((?:Sword|Claymore|Pole|Catalyst|Bow)_\w+)$/', $icon, $m)) {
            return sprintf('https://static.nanoka.cc/assets/gi/UI_Gacha_EquipIcon_%s.webp', $m[1]);
        }

        return null;
    }

    /**
     * HSR category of the item, shared by icons and artworks: explicit item type first, otherwise
     * the id shape (4 digits = character, 5+ digits starting with 2 = light cone).
     *
     * @return 'character'|'weapon'|null
     */
    private function detectHsrCategory(PullItemIconQuery $query): ?string
    {
        $itemId = $query->itemId !== null ? trim($query->itemId) : '';
        if ($itemId === '' || !ctype_digit($itemId)) {
            return null;
        }

        $type = $this->normalizeType($query->itemType);
        if ($type === 'character' || ($type === null && strlen($itemId) === 4)) {
            return 'character';
        }
        if ($type === 'weapon' || ($type === null && strlen($itemId) >= 5 && str_starts_with($itemId, '2'))) {
            return 'weapon';
        }

        return null;
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
