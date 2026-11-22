<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;

/**
 * S items offered by the manual S form: the S items of the game's Nanoka catalog, completed by the
 * recorded S pulls of every profile of the game (the catalog may lag behind a new release, or be
 * unavailable). Free text stays allowed, so item_types lists the types the user can pick for it.
 */
class ManualPullSuggestionProvider
{
    public function __construct(
        private readonly NanokaCatalogService $catalog,
        private readonly HoyoversePullRepository $pullRepository,
    ) {
    }

    /**
     * @return array{
     *     items: list<array{item_id: ?string, name: string, item_type: string, item_type_label: string, rarity: int}>,
     *     item_types: list<array{value: string, label: string}>
     * }
     */
    public function getSuggestions(GameInterface&HasPullTrackerInterface $gameService): array
    {
        $sRarity = $gameService->getRarityConfig()->getS()->getRarity();
        $catalogItems = $this->getCatalogItems($gameService);

        $items = [];
        $seenIds = [];
        $seenNames = [];
        $add = static function (?string $itemId, string $name, string $itemType, int $rarity) use (&$items, &$seenIds, &$seenNames): void {
            $name = trim($name);
            $itemId = $itemId !== null && $itemId !== '' ? $itemId : null;
            $normalizedName = PullGapPlanner::normalizeName($name);
            // The catalog repeats some names (one Traveler per element): one entry per item id and per name
            if ($name === '' || ($itemId !== null && isset($seenIds[$itemId])) || ($normalizedName !== '' && isset($seenNames[$normalizedName]))) {
                return;
            }
            if ($itemId !== null) {
                $seenIds[$itemId] = true;
            }
            if ($normalizedName !== '') {
                $seenNames[$normalizedName] = true;
            }
            $items[] = [
                'item_id' => $itemId,
                'name' => $name,
                'item_type' => $itemType,
                'item_type_label' => $itemType,
                'rarity' => $rarity,
            ];
        };

        foreach ($catalogItems as $record) {
            if ((int) ($record['rarity'] ?? 0) >= $sRarity) {
                $add((string) $record['id'], (string) $record['name'], (string) $record['item_type'], (int) $record['rarity']);
            }
        }
        foreach ($this->pullRepository->findDistinctRecordedSItems($gameService::getGameId(), $sRarity) as $row) {
            $add($row['itemId'], (string) $row['name'], (string) $row['itemType'], (int) $row['rarity']);
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $types = [];
        foreach ($catalogItems as $record) {
            $types[(string) $record['item_type']] = true;
        }
        foreach ($this->pullRepository->findDistinctRecordedItemTypes($gameService::getGameId()) as $type) {
            $types[(string) $type] = true;
        }
        unset($types['']);
        $types = array_keys($types);
        sort($types);

        return [
            'items' => $items,
            'item_types' => array_map(static fn (string $type): array => ['value' => $type, 'label' => $type], $types),
        ];
    }

    /**
     * The suggestion matching an item id, else a name (normalized like the merge of manual pulls).
     *
     * @return array{item_id: ?string, name: string, item_type: string, item_type_label: string, rarity: int}|null
     */
    public function findSuggestion(GameInterface&HasPullTrackerInterface $gameService, ?string $itemId, string $name): ?array
    {
        $items = $this->getSuggestions($gameService)['items'];

        if ($itemId !== null && $itemId !== '') {
            foreach ($items as $item) {
                if ($item['item_id'] === $itemId) {
                    return $item;
                }
            }
        }

        $normalizedName = PullGapPlanner::normalizeName($name);
        if ($normalizedName === '') {
            return null;
        }
        foreach ($items as $item) {
            if (PullGapPlanner::normalizeName($item['name']) === $normalizedName) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Every catalog record of the game (characters, weapons, light cones, bangboos), any rarity.
     *
     * @return list<array{id: string, name: string, rarity: int, item_type: string}>
     */
    private function getCatalogItems(GameInterface $gameService): array
    {
        $sections = match (true) {
            $gameService instanceof GenshinImpactService => [$this->catalog->getGiCatalog(), ['id_char', 'id_weapon']],
            $gameService instanceof HonkaiStarRailService => [$this->catalog->getHsrCatalog(), ['items_by_id']],
            $gameService instanceof ZenlessZoneZeroService => [$this->catalog->getZzzCatalog(), ['id_char', 'id_weapon', 'id_bangboo']],
            default => [[], []],
        };

        $records = [];
        [$catalog, $keys] = $sections;
        foreach ($keys as $key) {
            foreach ($catalog[$key] ?? [] as $record) {
                if (\is_array($record) && isset($record['id'], $record['name'], $record['item_type'])) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }
}
