<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of a HoYoverse banner catalog built from the irminsul.gg data API, one catalog per game.
 *
 * The catalog is stored as a single item in the game's persistent pool by hoyoverse:<game>-catalog:update and is
 * never built on demand: when it is missing, every lookup finds nothing and rate-up flags stay unknown.
 *
 * Shape:
 *   fetched_at,
 *   items:  [irminsul id => [id, name, display_name, rarity, category (character|weapon), release (game version, e.g. "3.4", or null)]],
 *   phases: list of [id, category, version, title, start, end, up, up_a], where start and end are
 *           [at (Y-m-d H:i:s), offset (hours from UTC, null = local time of the player's server)], "up" lists the
 *           S rate-up item ids and "up_a" the A ones (irminsul ids, which are the game item ids for HSR and ZZZ).
 *
 * The decoded catalog is memoised until reset(), which the kernel calls between worker messages.
 */
abstract class AbstractIrminsulCatalog implements ResetInterface
{
    public const string CATEGORY_CHARACTER = 'character';
    public const string CATEGORY_WEAPON = 'weapon';

    /** @var array{fetched_at: ?string, items: array<string, array<string, mixed>>, phases: list<array<string, mixed>>}|null */
    private ?array $catalog = null;

    /** @var array<string, list<string>>|null Normalized name or display name => item ids */
    private ?array $nameIndex = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Key of the catalog item in the game's pool (versioned with the shape).
     */
    abstract public function getCacheKey(): string;

    /**
     * Game slug of the irminsul.gg data API ("genshin", "hsr", "zzz").
     */
    abstract public static function getGameSlug(): string;

    /**
     * @return array{id: string, name: string, display_name: ?string, rarity: ?int, category: string, release: ?string}|null
     */
    public function findItem(string $id): ?array
    {
        $item = $this->all()['items'][trim($id)] ?? null;

        return is_array($item) ? $item : null;
    }

    /**
     * Item whose name or display name matches, ignoring case, spaces and punctuation; null when none or several do.
     *
     * @return array{id: string, name: string, display_name: ?string, rarity: ?int, category: string}|null
     */
    public function findItemByName(string $name): ?array
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        if ($this->nameIndex === null) {
            $this->nameIndex = [];
            foreach ($this->all()['items'] as $id => $item) {
                foreach (array_unique(array_filter([self::normalizeName((string) $item['name']), self::normalizeName((string) ($item['display_name'] ?? ''))])) as $key) {
                    $this->nameIndex[$key][] = (string) $id;
                }
            }
        }

        $ids = array_values(array_unique($this->nameIndex[$normalized] ?? []));

        return count($ids) === 1 ? $this->findItem($ids[0]) : null;
    }

    /**
     * Banner phases of a category, in the order of the data API (mostly chronological, not guaranteed).
     *
     * @return list<array{id: int, category: string, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, up: list<string>, up_a: list<string>}>
     */
    public function getPhases(string $category): array
    {
        return array_values(array_filter($this->all()['phases'], static fn (array $phase): bool => $phase['category'] === $category));
    }

    public function isLoaded(): bool
    {
        $catalog = $this->all();

        return $catalog['fetched_at'] !== null && $catalog['phases'] !== [];
    }

    public function getFetchedAt(): ?string
    {
        return $this->all()['fetched_at'];
    }

    /**
     * Whole stored catalog (empty sections when nothing has been built yet).
     *
     * @return array{fetched_at: ?string, items: array<string, array<string, mixed>>, phases: list<array<string, mixed>>}
     */
    public function all(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $item = $this->cache->getItem($this->getCacheKey());
        $stored = $item->isHit() ? $item->get() : null;

        return $this->catalog = self::normalizeCatalog(is_array($stored) ? $stored : []);
    }

    /**
     * Persists a catalog produced by the game's builder (without expiry) and refreshes the memo.
     *
     * @param array<string, mixed> $catalog
     */
    public function save(array $catalog): void
    {
        $normalized = self::normalizeCatalog($catalog);

        $item = $this->cache->getItem($this->getCacheKey());
        $item->set($normalized);
        $item->expiresAfter(null);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException(sprintf('The %s catalog could not be written to the cache pool.', static::getGameSlug()));
        }

        $this->catalog = $normalized;
        $this->nameIndex = null;
    }

    public function reset(): void
    {
        $this->catalog = null;
        $this->nameIndex = null;
    }

    /**
     * Lowercase letters and digits only: "Dan Heng • Imbibitor Lunae" and "Dan Heng Imbibitor Lunae" match.
     */
    public static function normalizeName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));
    }

    /**
     * Keeps only well-formed items and phases, so readers can rely on the shape documented above.
     *
     * @param array<string, mixed> $catalog
     *
     * @return array{fetched_at: ?string, items: array<string, array<string, mixed>>, phases: list<array<string, mixed>>}
     */
    private static function normalizeCatalog(array $catalog): array
    {
        $items = [];
        foreach (is_array($catalog['items'] ?? null) ? $catalog['items'] : [] as $id => $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || trim((string) $id) === '') {
                continue;
            }
            $items[(string) $id] = [
                'id' => (string) $id,
                'name' => $item['name'],
                'display_name' => is_string($item['display_name'] ?? null) && $item['display_name'] !== '' ? $item['display_name'] : null,
                'rarity' => is_int($item['rarity'] ?? null) ? $item['rarity'] : null,
                'category' => ($item['category'] ?? null) === self::CATEGORY_WEAPON ? self::CATEGORY_WEAPON : self::CATEGORY_CHARACTER,
                'release' => is_string($item['release'] ?? null) && $item['release'] !== '' ? $item['release'] : null,
            ];
        }

        $phases = [];
        foreach (is_array($catalog['phases'] ?? null) ? $catalog['phases'] : [] as $phase) {
            $start = is_array($phase) ? self::normalizeTime($phase['start'] ?? null) : null;
            $end = is_array($phase) ? self::normalizeTime($phase['end'] ?? null) : null;
            if ($start === null || $end === null || !in_array($phase['category'] ?? null, [self::CATEGORY_CHARACTER, self::CATEGORY_WEAPON], true)) {
                continue;
            }
            $phases[] = [
                'id' => is_int($phase['id'] ?? null) ? $phase['id'] : 0,
                'category' => $phase['category'],
                'version' => is_string($phase['version'] ?? null) ? $phase['version'] : '',
                'title' => is_string($phase['title'] ?? null) && $phase['title'] !== '' ? $phase['title'] : null,
                'start' => $start,
                'end' => $end,
                'up' => self::idList($phase['up'] ?? null),
                'up_a' => self::idList($phase['up_a'] ?? null),
            ];
        }

        return [
            'fetched_at' => is_string($catalog['fetched_at'] ?? null) && $catalog['fetched_at'] !== '' ? $catalog['fetched_at'] : null,
            'items' => $items,
            'phases' => $phases,
        ];
    }

    /**
     * @return array{at: string, offset: ?int}|null
     */
    private static function normalizeTime(mixed $time): ?array
    {
        if (!is_array($time) || !is_string($time['at'] ?? null) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $time['at'])) {
            return null;
        }

        return ['at' => $time['at'], 'offset' => is_int($time['offset'] ?? null) ? $time['offset'] : null];
    }

    /**
     * @return list<string>
     */
    private static function idList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', array_filter($ids, static fn (mixed $id): bool => (is_string($id) && $id !== '') || is_int($id)))));
    }
}
