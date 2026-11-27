<?php

namespace App\PerfectworldBundle\Service\Catalog;

use App\PerfectworldBundle\Service\NevernessToEvernessService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the compact NTE item catalog built from the NTE_Assets datamine.
 *
 * The catalog is stored as a single item in the persistent "perfectworld.nte_catalog.cache" pool by
 * perfectworld:nte-catalog:update and is never built on demand: when it is missing, every lookup
 * returns null and callers fall back to the export values.
 *
 * Shape:
 *   sha, version, built_at,
 *   items:   [lowercased id => [id, name, rank (S|A|B|null), type (character|arc|item|cosmetic), icon_path, preview_path]]:
 *            "preview_path" is the full artwork of the item (gacha splash or outfit art of a character, 1024px Arc,
 *            illustration of a cosmetic), null when it has none; catalogs built before the previews lack it and
 *            read as having none until the next rebuild,
 *   avatars: [lowercased avatar id => icon_path],
 *   pools:   [lowercased pool id => display name],
 *   banners: [lowercased pool id => [id, category (arc|board), name, up, others, up_guarantee, up_source, runs]]:
 *            the rotation banners with their featured items ("up": the rate-up Arc of an Arc pool, the
 *            featured S character of a limited board, always decided by NTE_Assets: "up_source" is
 *            "nte_assets"), the other S Arcs of an Arc pool ("others"), the featured guarantee of the Arc
 *            pools (in Arcs) and the periods the banner was open ("runs": [start_at, end_at] in UTC,
 *            "Y-m-d H:i:s", sorted by start; a banner can come back, so it may have several; empty when
 *            unknown).
 *
 * Lookups are case-insensitive (exports contain e.g. "Fashion_Glide_1075" for "Fashion_glide_1075").
 * The decoded catalog is memoised until reset(), which the kernel calls between worker messages.
 */
class NteCatalog implements ResetInterface
{
    public const string CACHE_KEY = 'nte_catalog_v1';
    public const string RAW_BASE_URL = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets';

    public const string TYPE_CHARACTER = 'character';
    public const string TYPE_ARC = 'arc';
    public const string TYPE_ITEM = 'item';
    public const string TYPE_COSMETIC = 'cosmetic';

    /** Banner categories of the "banners" section. */
    public const string BANNER_ARC = 'arc';
    public const string BANNER_BOARD = 'board';

    /** Source of the featured items of a banner ("up_source"): the NTE_Assets datamine. */
    public const string UP_SOURCE_NTE_ASSETS = 'nte_assets';

    /** Format of the run limits, in UTC. */
    public const string RUN_DATE_FORMAT = 'Y-m-d H:i:s';

    public const array RANK_TO_RARITY = [
        'S' => NevernessToEvernessService::RARITY_S,
        'A' => NevernessToEvernessService::RARITY_A,
        'B' => NevernessToEvernessService::RARITY_B,
    ];

    /** @var array{sha: ?string, version: ?string, built_at: ?string, items: array<string, array<string, mixed>>, avatars: array<string, string>, pools: array<string, string>, banners: array<string, array<string, mixed>>}|null */
    private ?array $catalog = null;

    public function __construct(
        #[Target('perfectworld.nte_catalog.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{id: string, name: string, rank: ?string, rarity: ?int, type: string, icon_path: ?string, preview_path: ?string}|null
     */
    public function find(string $rewardId): ?array
    {
        $entry = $this->all()['items'][self::normalizeId($rewardId)] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $rank = isset($entry['rank']) && is_string($entry['rank']) ? $entry['rank'] : null;

        return [
            'id' => (string) ($entry['id'] ?? $rewardId),
            'name' => (string) ($entry['name'] ?? ''),
            'rank' => $rank,
            'rarity' => $rank !== null ? (self::RANK_TO_RARITY[$rank] ?? null) : null,
            'type' => (string) ($entry['type'] ?? self::TYPE_ITEM),
            'icon_path' => isset($entry['icon_path']) && is_string($entry['icon_path']) ? $entry['icon_path'] : null,
            'preview_path' => is_string($entry['preview_path'] ?? null) && $entry['preview_path'] !== '' ? $entry['preview_path'] : null,
        ];
    }

    public function getIconUrl(string $rewardId): ?string
    {
        $iconPath = $this->find($rewardId)['icon_path'] ?? null;

        return $iconPath !== null ? $this->buildAssetUrl($iconPath) : null;
    }

    /**
     * Full artwork of an item, for the click-to-zoom preview of the pulls tracker: null when the item has none
     * (or the catalog predates the previews), and the preview then shows the icon.
     */
    public function getPreviewUrl(string $rewardId): ?string
    {
        $previewPath = $this->find($rewardId)['preview_path'] ?? null;

        return $previewPath !== null ? $this->buildAssetUrl($previewPath) : null;
    }

    /**
     * Profile avatar ids from the SDK role list (e.g. "Fashion_1042_1"): AvatarDataTable first, then
     * any catalog item with the same id.
     */
    public function findAvatarIconUrl(?string $avatarId): ?string
    {
        if ($avatarId === null || trim($avatarId) === '') {
            return null;
        }

        $iconPath = $this->all()['avatars'][self::normalizeId($avatarId)] ?? null;
        if (is_string($iconPath) && $iconPath !== '') {
            return $this->buildAssetUrl($iconPath);
        }

        return $this->getIconUrl($avatarId);
    }

    /**
     * Display name of a rotation pool from the export (e.g. "Lottery_HeiYu", "ForkLottery_MingYin").
     */
    public function findPoolName(?string $poolId): ?string
    {
        if ($poolId === null || trim($poolId) === '') {
            return null;
        }

        $name = $this->all()['pools'][self::normalizeId($poolId)] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Rotation banner of an export pool id (e.g. "ForkLottery_HeiYu", "Lottery_HeiYu"), with its featured items
     * and runs. Catalogs built before the runs read as without them; stored keys outside this shape are ignored.
     *
     * @return array{id: string, category: string, name: ?string, up: list<string>, others: list<string>, up_guarantee: ?int, up_source: string, runs: list<array{start_at: string, end_at: string}>}|null
     */
    public function findPool(?string $poolId): ?array
    {
        if ($poolId === null || trim($poolId) === '') {
            return null;
        }

        $entry = $this->all()['banners'][self::normalizeId($poolId)] ?? null;
        if (!is_array($entry) || !in_array($entry['category'] ?? null, [self::BANNER_ARC, self::BANNER_BOARD], true)) {
            return null;
        }

        $ids = static fn (mixed $list): array => is_array($list)
            ? array_values(array_filter($list, static fn (mixed $id): bool => is_string($id) && $id !== ''))
            : [];

        return [
            'id' => is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : trim($poolId),
            'category' => $entry['category'],
            'name' => is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : null,
            'up' => $ids($entry['up'] ?? null),
            'others' => $ids($entry['others'] ?? null),
            'up_guarantee' => is_int($entry['up_guarantee'] ?? null) ? $entry['up_guarantee'] : null,
            'up_source' => is_string($entry['up_source'] ?? null) && $entry['up_source'] !== '' ? $entry['up_source'] : self::UP_SOURCE_NTE_ASSETS,
            'runs' => self::normalizeRuns($entry['runs'] ?? null),
        ];
    }

    /**
     * Whether an S Arc was the rate-up Arc of its pull.
     *
     * With a pool the catalog knows, the pool decides. Without one (exports older than nte-exporter 0.5,
     * manual pulls, a pool newer than the catalog), every Arc banner does: an Arc only ever featured is
     * a limited Arc, which drops nowhere else; one only ever listed among the other S is a standard Arc.
     * Anything else (unknown Arc, or an Arc both featured and not) is null.
     */
    public function isArcUp(string $itemId, ?string $poolId = null): ?bool
    {
        $itemId = self::normalizeId($itemId);
        if ($itemId === '') {
            return null;
        }

        $pool = $this->findPool($poolId);
        if ($pool !== null && $pool['category'] === self::BANNER_ARC && $pool['up'] !== []) {
            return in_array($itemId, array_map(self::normalizeId(...), $pool['up']), true);
        }

        $featured = false;
        $standard = false;
        foreach (array_keys($this->all()['banners']) as $bannerId) {
            $banner = $this->findPool((string) $bannerId);
            if ($banner === null || $banner['category'] !== self::BANNER_ARC) {
                continue;
            }
            $featured = $featured || in_array($itemId, array_map(self::normalizeId(...), $banner['up']), true);
            $standard = $standard || in_array($itemId, array_map(self::normalizeId(...), $banner['others']), true);
        }

        return $featured !== $standard ? $featured : null;
    }

    public function isLoaded(): bool
    {
        $catalog = $this->all();

        return $catalog['sha'] !== null && $catalog['items'] !== [];
    }

    public function getSha(): ?string
    {
        return $this->all()['sha'];
    }

    public function getVersion(): ?string
    {
        return $this->all()['version'];
    }

    public function getBuiltAt(): ?string
    {
        return $this->all()['built_at'];
    }

    /**
     * Whole stored catalog (empty sections when nothing has been built yet).
     *
     * @return array{sha: ?string, version: ?string, built_at: ?string, items: array<string, array<string, mixed>>, avatars: array<string, string>, pools: array<string, string>, banners: array<string, array<string, mixed>>}
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
     * Persists a catalog produced by NteCatalogBuilder (without expiry) and refreshes the memo.
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
            throw new \RuntimeException('The NTE catalog could not be written to the cache pool.');
        }

        $this->catalog = $normalized;
    }

    public function reset(): void
    {
        $this->catalog = null;
    }

    /**
     * Absolute raw.githubusercontent.com URL of a repository file at the catalog commit.
     */
    public function buildAssetUrl(string $path): ?string
    {
        $sha = $this->getSha();
        $path = trim($path, '/');
        if ($sha === null || $path === '') {
            return null;
        }

        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return sprintf('%s/%s/%s', self::RAW_BASE_URL, $sha, $encodedPath);
    }

    public static function normalizeId(string $id): string
    {
        return strtolower(trim($id));
    }

    /**
     * Runs of a banner: the valid [start_at, end_at] pairs ("Y-m-d H:i:s", end not before start), without
     * duplicates, sorted by start then end. Anything that is not a list of runs reads as no run.
     *
     * @return list<array{start_at: string, end_at: string}>
     */
    public static function normalizeRuns(mixed $runs): array
    {
        if (!is_array($runs)) {
            return [];
        }

        $normalized = [];
        foreach ($runs as $run) {
            $startAt = is_array($run) ? self::runDate($run['start_at'] ?? null) : null;
            $endAt = is_array($run) ? self::runDate($run['end_at'] ?? null) : null;
            // Same fixed-width format: the string order is the chronological one
            if ($startAt !== null && $endAt !== null && strcmp($startAt, $endAt) <= 0) {
                $normalized[$startAt . '|' . $endAt] = ['start_at' => $startAt, 'end_at' => $endAt];
            }
        }
        ksort($normalized, \SORT_STRING);

        return array_values($normalized);
    }

    private static function runDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . self::RUN_DATE_FORMAT, $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format(self::RUN_DATE_FORMAT) === $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $catalog
     *
     * @return array{sha: ?string, version: ?string, built_at: ?string, items: array<string, array<string, mixed>>, avatars: array<string, string>, pools: array<string, string>, banners: array<string, array<string, mixed>>}
     */
    private static function normalizeCatalog(array $catalog): array
    {
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            'sha' => $string($catalog['sha'] ?? null),
            'version' => $string($catalog['version'] ?? null),
            'built_at' => $string($catalog['built_at'] ?? null),
            'items' => is_array($catalog['items'] ?? null) ? $catalog['items'] : [],
            'avatars' => is_array($catalog['avatars'] ?? null) ? $catalog['avatars'] : [],
            'pools' => is_array($catalog['pools'] ?? null) ? $catalog['pools'] : [],
            // Missing in catalogs built before the banners were added: findPool() then finds nothing
            'banners' => is_array($catalog['banners'] ?? null) ? $catalog['banners'] : [],
        ];
    }
}
