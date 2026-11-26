<?php

namespace App\GryphlineBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the compact Endfield catalog (operators, weapons and gacha pools) built from AkeData.
 *
 * The catalog is stored as a single item in the persistent "gryphline.ake_catalog.cache" pool by
 * gryphline:ake-catalog:update and is never built on demand: when it is missing, every lookup
 * returns null and callers fall back to the pulls already stored.
 *
 * Shape:
 *   version (AkeData manifest version id, e.g. "1.6.4@11944529-13"), built_at,
 *   items: [item id => [id, name, rarity (1-6|null), type (char|weapon)]],
 *   pools: [pool id => [id, category (char|weapon), type (int, see the *_POOL_TYPE_* constants),
 *           up (list of rate-up item ids, empty for the standard and beginner pools)]].
 *
 * Item ids are kept verbatim: they equal gryphline_pull.item_id, and the types match its pool_category.
 * Pool ids equal gryphline_pull.pool_id and the pool categories match its pool_category too. Catalogs
 * stored before the pools were added have no "pools" entry: they read as an empty pool list.
 * The decoded catalog is memoised until reset(), which the kernel calls between worker messages.
 */
class AkeCatalog implements ResetInterface
{
    public const string CACHE_KEY = 'ake_catalog_v1';

    public const string TYPE_CHAR = 'char';
    public const string TYPE_WEAPON = 'weapon';

    /** GachaCharPoolTable "type" values. */
    public const int CHAR_POOL_TYPE_SPECIAL = 0;
    public const int CHAR_POOL_TYPE_BEGINNER = 1;
    public const int CHAR_POOL_TYPE_STANDARD = 2;
    public const int CHAR_POOL_TYPE_JOINT = 3;
    public const int CHAR_POOL_TYPE_RERUN = 4;

    /** GachaWeaponPoolTable "type" values (the permanent weapon pools are normal ones too). */
    public const int WEAPON_POOL_TYPE_NORMAL = 0;
    public const int WEAPON_POOL_TYPE_RERUN = 1;

    /** Item id prefix => catalog type. */
    public const array ID_PREFIX_TO_TYPE = [
        'chr_' => self::TYPE_CHAR,
        'wpn_' => self::TYPE_WEAPON,
    ];

    /** @var array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>}>}|null */
    private ?array $catalog = null;

    public function __construct(
        #[Target('gryphline.ake_catalog.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{id: string, name: string, rarity: ?int, type: string}|null
     */
    public function find(string $itemId): ?array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return null;
        }

        $entry = $this->all()['items'][$itemId] ?? null;
        if (!is_array($entry) || !is_string($entry['name'] ?? null) || $entry['name'] === '') {
            return null;
        }

        $type = is_string($entry['type'] ?? null) ? $entry['type'] : self::typeFromItemId($itemId);
        if ($type === null) {
            return null;
        }

        return [
            'id' => $itemId,
            'name' => $entry['name'],
            'rarity' => is_int($entry['rarity'] ?? null) ? $entry['rarity'] : null,
            'type' => $type,
        ];
    }

    /**
     * Gacha pool by id, null when no catalog is stored, when the stored catalog predates the pools or
     * when the pool is unknown.
     *
     * @return array{id: string, category: string, type: int, up: list<string>}|null
     */
    public function findPool(string $poolId): ?array
    {
        $poolId = trim($poolId);
        if ($poolId === '') {
            return null;
        }

        $entry = $this->all()['pools'][$poolId] ?? null;
        if (!is_array($entry) || !is_int($entry['type'] ?? null)) {
            return null;
        }

        $category = $entry['category'] ?? null;
        if (!in_array($category, [self::TYPE_CHAR, self::TYPE_WEAPON], true)) {
            return null;
        }

        $up = is_array($entry['up'] ?? null) ? $entry['up'] : [];

        return [
            'id' => $poolId,
            'category' => $category,
            'type' => $entry['type'],
            'up' => array_values(array_filter($up, static fn (mixed $itemId): bool => is_string($itemId) && $itemId !== '')),
        ];
    }

    public function isLoaded(): bool
    {
        $catalog = $this->all();

        return $catalog['version'] !== null && $catalog['items'] !== [];
    }

    /**
     * AkeData manifest version id the catalog was built from (e.g. "1.6.4@11944529-13").
     */
    public function getVersion(): ?string
    {
        return $this->all()['version'];
    }

    public function getBuiltAt(): ?string
    {
        return $this->all()['built_at'];
    }

    /**
     * Whole stored catalog (no items when nothing has been built yet, no pools when nothing has been
     * built yet or the stored catalog predates them).
     *
     * @return array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>}>}
     */
    public function all(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $stored = $item->isHit() ? $item->get() : null;

        return $this->catalog = self::normalizeCatalog(is_array($stored) ? $stored : []);
    }

    /**
     * Persists a catalog produced by AkeCatalogBuilder (without expiry) and refreshes the memo.
     *
     * @param array<string, mixed> $catalog
     */
    public function save(array $catalog): void
    {
        $normalized = self::normalizeCatalog($catalog);

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($normalized);
        $item->expiresAfter(null);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('The Endfield catalog could not be written to the cache pool.');
        }

        $this->catalog = $normalized;
    }

    public function reset(): void
    {
        $this->catalog = null;
    }

    /**
     * Catalog type of an item id ("chr_0005_chen" => "char", "wpn_funnel_0011" => "weapon"), null for
     * anything that is neither an operator nor a weapon.
     */
    public static function typeFromItemId(string $itemId): ?string
    {
        foreach (self::ID_PREFIX_TO_TYPE as $prefix => $type) {
            if (str_starts_with($itemId, $prefix)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $catalog
     *
     * @return array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>}>}
     */
    private static function normalizeCatalog(array $catalog): array
    {
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            'version' => $string($catalog['version'] ?? null),
            'built_at' => $string($catalog['built_at'] ?? null),
            'items' => is_array($catalog['items'] ?? null) ? $catalog['items'] : [],
            'pools' => is_array($catalog['pools'] ?? null) ? $catalog['pools'] : [],
        ];
    }
}
