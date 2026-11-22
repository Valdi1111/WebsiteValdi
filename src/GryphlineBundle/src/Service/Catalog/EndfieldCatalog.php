<?php

namespace App\GryphlineBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the compact Endfield item catalog (operators and weapons) built from AkeData.
 *
 * The catalog is stored as a single item in the persistent "gryphline.endfield_catalog.cache" pool by
 * gryphline:endfield-catalog:update and is never built on demand: when it is missing, every lookup
 * returns null and callers fall back to the pulls already stored.
 *
 * Shape:
 *   version (AkeData manifest version id, e.g. "1.6.4@11944529-13"), built_at,
 *   items: [item id => [id, name, rarity (1-6|null), type (char|weapon)]].
 *
 * Item ids are kept verbatim: they equal gryphline_pull.item_id, and the types match its pool_category.
 * The decoded catalog is memoised until reset(), which the kernel calls between worker messages.
 */
class EndfieldCatalog implements ResetInterface
{
    public const string CACHE_KEY = 'endfield_catalog_v1';

    public const string TYPE_CHAR = 'char';
    public const string TYPE_WEAPON = 'weapon';

    /** Item id prefix => catalog type. */
    public const array ID_PREFIX_TO_TYPE = [
        'chr_' => self::TYPE_CHAR,
        'wpn_' => self::TYPE_WEAPON,
    ];

    /** @var array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>}|null */
    private ?array $catalog = null;

    public function __construct(
        #[Target('gryphline.endfield_catalog.cache')]
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
     * Whole stored catalog (no items when nothing has been built yet).
     *
     * @return array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>}
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
     * Persists a catalog produced by EndfieldCatalogBuilder (without expiry) and refreshes the memo.
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
     * @return array{version: ?string, built_at: ?string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>}
     */
    private static function normalizeCatalog(array $catalog): array
    {
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            'version' => $string($catalog['version'] ?? null),
            'built_at' => $string($catalog['built_at'] ?? null),
            'items' => is_array($catalog['items'] ?? null) ? $catalog['items'] : [],
        ];
    }
}
