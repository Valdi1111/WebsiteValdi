<?php

namespace App\GryphlineBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulException;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the compact Endfield catalog (operators, weapons and gacha pools) from the AkeData datamine.
 *
 * The manifest gives the TableCfg directory of each published game version. ItemTable.json holds the
 * items keyed by id, with an empty "name.text" and the text id in "name.id"; the English name comes
 * from I18nTextTable_EN.json (text id => string, ~14 MB). The item table is reduced to the operator
 * and weapon rows before the text table is downloaded, and each table is released as soon as it has
 * been read, so only one of them is in memory at a time. The small GachaCharPoolTable.json and
 * GachaWeaponPoolTable.json (pool id => type and rate-up item ids) are read last. This only runs from
 * the console or a worker.
 *
 * Any item problem (unknown version, missing or invalid item or text table, no resolvable name) throws
 * before anything is saved, so the previous catalog stays in place. A pool table that cannot be read
 * only logs a warning and leaves the pools of its category empty: the items are what matters. The
 * result is not merged with the previous catalog: every AkeData version ships the full tables.
 *
 * The banner dates, and the featured 6★ of most pools, come from irminsul.gg (banner-characters.json and
 * banner-weapons.json, normalized with IrminsulTimeRules::endfield()). The featured names are resolved to
 * irminsul ids (characters.json, weapons.json), then to the AkeData item ids: operator 10NN is
 * "chr_00NN_*", weapon T*10000+NN is "wpn_<type>_00NN" (T: 1 sword, 2 funnel, 3 claym, 5 lance, 6 pistol).
 * Each irminsul banner goes to the pool featuring its items, the AkeData rate-ups of a candidate pool being
 * all among the featured 6★ of the banner:
 *  - a "RE-Factor" banner to the one candidate rerun pool (several banners on one rerun pool are its runs
 *    #1, #2...);
 *  - any other banner to a candidate limited pool ("special_X_Y_Z" or "joint_X_Y_Z" for the operators,
 *    "weponbox_X_Y_Z" or "weaponbox_X_Y_Z" for the weapons; never a rerun, standard, beginner or "constant"
 *    pool): the one named after its exact version, else the only one of its major.minor. The pool ids do not
 *    follow the release order (1.0.2 features Gilberta in "special_1_0_3", 1.0.3 Yvonne in "special_1_0_2"),
 *    and a later banner featuring an old item never takes a pool of another major.minor. A weapon banner
 *    featuring several 6★ also owns the unmapped weapon pools of its major.minor whose AkeData rate-ups are
 *    among them (1.2.2: one pool per featured weapon), each of these pools keeping its own featured weapons;
 *    without an exact version pool, it is only skipped (with a warning) when none of these pools is left for it.
 * A mapped pool gets its runs (UTC) and, when every featured name resolved, the irminsul 6★ as "up"
 * (up_source irminsul, warning when AkeData disagrees, as for the Joint banner where AkeData lists two of
 * its four operators); the others keep the AkeData list (up_source akedata).
 * irminsul.gg being unreadable never fails the build: the runs, and the irminsul rate-ups, of the previous
 * catalog are kept for the same pool ids.
 */
class AkeCatalogBuilder
{
    private const string MANIFEST_PATH = 'manifest.json';
    private const string ITEM_TABLE_FILE = 'ItemTable.json';
    private const string TEXT_TABLE_FILE = 'I18nTextTable_EN.json';

    /** Per catalog type: pool table file and field holding the rate-up item ids. */
    private const array POOL_TABLES = [
        AkeCatalog::TYPE_CHAR => ['file' => 'GachaCharPoolTable.json', 'up_field' => 'upCharIds'],
        AkeCatalog::TYPE_WEAPON => ['file' => 'GachaWeaponPoolTable.json', 'up_field' => 'upWeaponIds'],
    ];

    /** irminsul.gg item file and banner file, per catalog type */
    private const array IRMINSUL_FILES = [
        AkeCatalog::TYPE_CHAR => ['items' => IrminsulClient::FILE_CHARACTERS, 'banners' => IrminsulClient::FILE_BANNER_CHARACTERS],
        AkeCatalog::TYPE_WEAPON => ['items' => IrminsulClient::FILE_WEAPONS, 'banners' => IrminsulClient::FILE_BANNER_WEAPONS],
    ];

    private const string IRMINSUL_GAME = 'endfield';

    /** irminsul rarity of the featured items that count */
    private const int FEATURED_RARITY = 6;

    /** Rerun pool type of each catalog type */
    private const array RERUN_POOL_TYPES = [
        AkeCatalog::TYPE_CHAR => AkeCatalog::CHAR_POOL_TYPE_RERUN,
        AkeCatalog::TYPE_WEAPON => AkeCatalog::WEAPON_POOL_TYPE_RERUN,
    ];

    /** Limited pool of a version, per catalog type (both weapon spellings): groups 1, 2 and 3 are its major, minor and patch */
    private const array VERSION_POOL_PATTERNS = [
        AkeCatalog::TYPE_CHAR => '/^(?:special|joint)_(\d+)_(\d+)_(\d+)$/',
        AkeCatalog::TYPE_WEAPON => '/^we(?:a)?ponbox_(\d+)_(\d+)_(\d+)$/',
    ];

    /** Pool types that are never the pool of a regular banner, per catalog type */
    private const array NON_LIMITED_POOL_TYPES = [
        AkeCatalog::TYPE_CHAR => [AkeCatalog::CHAR_POOL_TYPE_RERUN, AkeCatalog::CHAR_POOL_TYPE_STANDARD, AkeCatalog::CHAR_POOL_TYPE_BEGINNER],
        AkeCatalog::TYPE_WEAPON => [AkeCatalog::WEAPON_POOL_TYPE_RERUN],
    ];

    /** Weapon type of the irminsul weapon ids (T*10000+NN) */
    private const array IRMINSUL_WEAPON_TYPES = [1 => 'sword', 2 => 'funnel', 3 => 'claym', 5 => 'lance', 6 => 'pistol'];

    /** Relative TableCfg directory published by the manifest (e.g. "public/1.6.4/11944529-13/TableCfg"). */
    private const string TABLE_CFG_PATH_PATTERN = '#^[A-Za-z0-9_@-][A-Za-z0-9._@-]*(/[A-Za-z0-9_@-][A-Za-z0-9._@-]*)*$#';

    public function __construct(
        #[Target('gryphline.akedata.client')]
        private readonly HttpClientInterface $httpClient,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
        private readonly IrminsulClient $irminsulClient,
        private readonly IrminsulPhaseNormalizer $irminsulNormalizer,
        /** Catalog stored before this build: its irminsul fields are kept when irminsul.gg cannot be read */
        private readonly AkeCatalog $previousCatalog,
    ) {
    }

    /**
     * Builds the catalog of the given manifest version id ("latest" when null). The result is not saved:
     * callers persist it with AkeCatalog::save().
     *
     * @return array{version: string, built_at: string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}>}
     *
     * @throws \RuntimeException when the build is aborted (the previous catalog must be kept)
     */
    public function build(?string $versionId = null): array
    {
        $versionId = $versionId !== null && trim($versionId) !== '' ? trim($versionId) : null;
        ['id' => $versionId, 'table_cfg_path' => $tableCfgPath] = $this->resolveVersion($versionId);

        // The decoded tables are only referenced by the argument, so they are freed when the call returns
        $candidates = $this->collectCandidates($this->fetchJson(sprintf('%s/%s', $tableCfgPath, self::ITEM_TABLE_FILE)));
        if ($candidates === []) {
            throw new \RuntimeException(sprintf('AkeData %s of version %s has no operator or weapon.', self::ITEM_TABLE_FILE, $versionId));
        }

        $items = $this->resolveNames($candidates, $this->fetchJson(sprintf('%s/%s', $tableCfgPath, self::TEXT_TABLE_FILE)));
        if ($items === []) {
            throw new \RuntimeException(sprintf('No operator or weapon name could be resolved from AkeData %s of version %s.', self::TEXT_TABLE_FILE, $versionId));
        }
        ksort($items, \SORT_STRING);

        $pools = [];
        $poolCounts = [];
        foreach (array_keys(self::POOL_TABLES) as $category) {
            $categoryPools = $this->collectPools($tableCfgPath, $versionId, $category);
            $poolCounts[$category] = count($categoryPools);
            $pools += $categoryPools;
        }
        ksort($pools, \SORT_STRING);

        $pools = $this->applyIrminsulBanners($pools, $items, $versionId);

        $this->logger->info('Endfield catalog built', [
            'version' => $versionId,
            'items' => count($items),
            'unresolved' => count($candidates) - count($items),
            'char_pools' => $poolCounts[AkeCatalog::TYPE_CHAR],
            'weapon_pools' => $poolCounts[AkeCatalog::TYPE_WEAPON],
            'banner_runs' => array_sum(array_map(static fn (array $pool): int => count($pool['runs']), $pools)),
            'irminsul_up_pools' => count(array_filter($pools, static fn (array $pool): bool => $pool['up_source'] === AkeCatalog::UP_SOURCE_IRMINSUL)),
        ]);

        return [
            'version' => $versionId,
            'built_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'items' => $items,
            'pools' => $pools,
        ];
    }

    /**
     * @return array{id: string, table_cfg_path: string}
     */
    private function resolveVersion(?string $versionId): array
    {
        $manifest = $this->fetchJson(self::MANIFEST_PATH);

        $versionId ??= is_string($manifest['latest'] ?? null) && $manifest['latest'] !== '' ? $manifest['latest'] : null;
        if ($versionId === null) {
            throw new \RuntimeException('The AkeData manifest has no latest version.');
        }

        foreach (is_array($manifest['versions'] ?? null) ? $manifest['versions'] : [] as $version) {
            if (!is_array($version) || ($version['id'] ?? null) !== $versionId) {
                continue;
            }

            $tableCfgPath = is_string($version['tableCfgPath'] ?? null) ? trim($version['tableCfgPath'], '/') : '';
            if (!preg_match(self::TABLE_CFG_PATH_PATTERN, $tableCfgPath) || in_array('..', explode('/', $tableCfgPath), true)) {
                throw new \RuntimeException(sprintf('The AkeData manifest has no usable tableCfgPath for version "%s".', $versionId));
            }

            return ['id' => $versionId, 'table_cfg_path' => $tableCfgPath];
        }

        throw new \RuntimeException(sprintf('The AkeData manifest has no version "%s".', $versionId));
    }

    /**
     * Keeps the operator and weapon rows of ItemTable with the text id of their name.
     *
     * @param array<int|string, mixed> $itemTable
     *
     * @return array<string, array{text_id: string, rarity: ?int, type: string}>
     */
    private function collectCandidates(array $itemTable): array
    {
        $candidates = [];
        foreach ($itemTable as $itemId => $entry) {
            $itemId = (string) $itemId;
            $type = AkeCatalog::typeFromItemId($itemId);
            if ($type === null || !is_array($entry)) {
                continue;
            }

            // Text ids are signed 64-bit integers (decoded as strings beyond PHP_INT_MAX); 0 means no text
            $textId = is_array($entry['name'] ?? null) ? ($entry['name']['id'] ?? null) : null;
            $textId = is_int($textId) || (is_string($textId) && preg_match('/^-?\d+$/', $textId)) ? (string) $textId : null;
            if ($textId === null || $textId === '0') {
                continue;
            }

            $rarity = $entry['rarity'] ?? null;
            $candidates[$itemId] = [
                'text_id' => $textId,
                'rarity' => is_int($rarity) && $rarity >= 1 && $rarity <= 6 ? $rarity : null,
                'type' => $type,
            ];
        }

        return $candidates;
    }

    /**
     * Candidates whose English name is missing or blank are dropped.
     *
     * @param array<string, array{text_id: string, rarity: ?int, type: string}> $candidates
     * @param array<int|string, mixed> $texts
     *
     * @return array<string, array{id: string, name: string, rarity: ?int, type: string}>
     */
    private function resolveNames(array $candidates, array $texts): array
    {
        $items = [];
        foreach ($candidates as $itemId => $candidate) {
            // Numeric string keys are stored as integers by PHP, so the string text id finds them
            $name = $texts[$candidate['text_id']] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $items[$itemId] = [
                'id' => $itemId,
                'name' => trim($name),
                'rarity' => $candidate['rarity'],
                'type' => $candidate['type'],
            ];
        }

        return $items;
    }

    /**
     * Pools of one category (char or weapon) keyed by pool id. Rows without an integer type are dropped
     * (0 is a real pool type, so it cannot serve as a default). A table that cannot be read or holds no
     * usable pool logs a warning and yields no pool instead of aborting the build.
     *
     * @return array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}>
     */
    private function collectPools(string $tableCfgPath, string $versionId, string $category): array
    {
        ['file' => $file, 'up_field' => $upField] = self::POOL_TABLES[$category];

        try {
            $poolTable = $this->fetchJson(sprintf('%s/%s', $tableCfgPath, $file));
        } catch (\RuntimeException $e) {
            $this->logger->warning('Endfield catalog built without the pools of a table', [
                'version' => $versionId,
                'table' => $file,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $pools = [];
        foreach ($poolTable as $poolId => $entry) {
            $poolId = (string) $poolId;
            if ($poolId === '' || !is_array($entry) || !is_int($entry['type'] ?? null)) {
                continue;
            }

            $up = is_array($entry[$upField] ?? null) ? $entry[$upField] : [];
            $pools[$poolId] = [
                'id' => $poolId,
                'category' => $category,
                'type' => $entry['type'],
                'up' => array_values(array_filter($up, static fn (mixed $itemId): bool => is_string($itemId) && $itemId !== '')),
                'up_source' => AkeCatalog::UP_SOURCE_AKEDATA,
                'runs' => [],
            ];
        }

        if ($pools === []) {
            $this->logger->warning('Endfield catalog built without the pools of a table', [
                'version' => $versionId,
                'table' => $file,
                'error' => 'No pool with an integer type.',
            ]);
        }

        return $pools;
    }

    /**
     * Runs and featured items of the pools from the irminsul.gg banners (see the class doc). When irminsul.gg
     * cannot be read, the irminsul fields of the previous catalog are kept for the same pool ids.
     *
     * @param array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}> $pools
     * @param array<string, array{id: string, name: string, rarity: ?int, type: string}> $items
     *
     * @return array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}>
     */
    private function applyIrminsulBanners(array $pools, array $items, string $versionId): array
    {
        if ($pools === []) {
            return $pools;
        }

        try {
            $banners = $this->fetchIrminsulBanners($items);
        } catch (IrminsulException $e) {
            $this->logger->warning('Endfield catalog built without the irminsul.gg banners: the previous runs and rate-ups are kept', [
                'version' => $versionId,
                'error' => $e->getMessage(),
            ]);

            return $this->keepPreviousIrminsulFields($pools);
        }

        // A category without pools (pool table unreadable, already logged) has nothing to map
        $categoriesWithPools = array_flip(array_column($pools, 'category'));
        $banners = array_values(array_filter($banners, static fn (array $banner): bool => isset($categoriesWithPools[$banner['category']])));

        // Pass 1: each banner to its RE-Factor or version pool
        /** @var array<string, list<int>> $poolBanners pool id => banner indexes */
        $poolBanners = [];
        /** @var array<int, list<string>> $bannerPools banner index => pool ids */
        $bannerPools = [];
        /** @var array<int, list<string>> $deferred multi-6★ weapon banner index => candidate pools, left to pass 2 */
        $deferred = [];
        foreach ($banners as $index => $banner) {
            if ($banner['is_re_factor']) {
                $poolId = $this->findRerunPool($banner, $pools);
            } else {
                $candidates = $this->findFeaturedPoolCandidates($banner, $pools);
                $poolId = count($candidates) === 1 ? $candidates[0] : null;
                if (count($candidates) > 1 && self::isMultiWeaponBanner($banner)) {
                    // Several pools are expected (one per featured weapon): pass 2 maps them
                    $deferred[$index] = $candidates;
                } elseif ($poolId === null) {
                    $this->warnBannerSkipped($banner, $candidates, $candidates === [] ? 'no pool of its major.minor features its items' : 'several pools of its major.minor feature its items');
                }
            }
            if ($poolId !== null) {
                $poolBanners[$poolId][] = $index;
                $bannerPools[$index] = [$poolId];
            }
        }

        // Pass 2: a weapon banner featuring several 6★ also owns the other weapon pools of its major.minor
        foreach ($banners as $index => $banner) {
            if (!self::isMultiWeaponBanner($banner)) {
                continue;
            }
            foreach ($this->findSiblingWeaponPools($banner, $pools, $poolBanners) as $poolId) {
                $poolBanners[$poolId][] = $index;
                $bannerPools[$index][] = $poolId;
            }
            if (isset($deferred[$index]) && !isset($bannerPools[$index])) {
                $this->warnBannerSkipped($banner, $deferred[$index], 'several pools of its major.minor feature its items, none is left for it');
            }
        }

        // The AkeData lists, as the overrides below must not change what the other pools compare to
        $akeDataUps = array_map(static fn (array $pool): array => $pool['up'], $pools);
        foreach ($poolBanners as $poolId => $indexes) {
            usort($indexes, static fn (int $a, int $b): int => [$banners[$a]['start_at'], $a] <=> [$banners[$b]['start_at'], $b]);

            $pools[$poolId]['runs'] = array_map(static fn (int $index): array => [
                'start_at' => $banners[$index]['start_at'],
                'end_at' => $banners[$index]['end_at'],
            ], $indexes);

            $up = [];
            $complete = true;
            foreach ($indexes as $index) {
                $complete = $complete && $banners[$index]['complete'];
                array_push($up, ...self::featuredOfPool($banners[$index]['codes'], $poolId, $bannerPools[$index], $akeDataUps));
            }
            $up = array_values(array_unique($up));
            if (!$complete || $up === []) {
                continue;
            }

            $akeDataUp = $akeDataUps[$poolId];
            if (array_diff($up, $akeDataUp) !== [] || array_diff($akeDataUp, $up) !== []) {
                $this->logger->warning('Endfield pool rate-ups taken from irminsul.gg, AkeData lists others', [
                    'version' => $versionId,
                    'pool' => $poolId,
                    'irminsul_versions' => array_map(static fn (int $index): string => $banners[$index]['version'], $indexes),
                    'irminsul_up' => $up,
                    'akedata_up' => $akeDataUp,
                ]);
            }
            $pools[$poolId]['up'] = $up;
            $pools[$poolId]['up_source'] = AkeCatalog::UP_SOURCE_IRMINSUL;
        }

        return $pools;
    }

    /**
     * irminsul.gg banners of both categories, with their featured 6★ as AkeData item ids ("codes"; "complete"
     * is false when a featured name could not be resolved) and their limits in UTC.
     *
     * @param array<string, array{id: string, name: string, rarity: ?int, type: string}> $items
     *
     * @return list<array{category: string, version: string, title: ?string, is_re_factor: bool, start_at: string, end_at: string, codes: list<string>, complete: bool}>
     *
     * @throws IrminsulException when a file cannot be read or a banner file has no usable banner
     */
    private function fetchIrminsulBanners(array $items): array
    {
        // AkeData item ids by irminsul key ("char:0013", "weapon:funnel:0011")
        $itemIdsByKey = [];
        foreach (array_keys($items) as $itemId) {
            $key = self::akeDataItemKey((string) $itemId);
            if ($key !== null) {
                $itemIdsByKey[$key][] = (string) $itemId;
            }
        }

        // Every file is read before anything is mapped: a partial read is no read
        $irminsulItems = [];
        $rawBanners = [];
        foreach (self::IRMINSUL_FILES as $category => ['items' => $itemFile, 'banners' => $bannerFile]) {
            foreach ($this->irminsulClient->fetch(self::IRMINSUL_GAME, $itemFile) as $entry) {
                $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
                if (is_int($entry['id'] ?? null) && $name !== '') {
                    $irminsulItems[$category][$name][] = ['id' => $entry['id'], 'rarity' => is_int($entry['rarity'] ?? null) ? $entry['rarity'] : null];
                }
            }
            $rawBanners[$category] = $this->irminsulClient->fetch(self::IRMINSUL_GAME, $bannerFile);
        }

        $banners = [];
        $warned = [];
        foreach ($rawBanners as $category => $entries) {
            // The builder logger, so the rewrites and drops land in the gryphline log
            $phases = $this->irminsulNormalizer->normalize($entries, $category, IrminsulTimeRules::endfield(), $this->logger);
            if ($phases === []) {
                throw new IrminsulException(sprintf('irminsul.gg %s/%s.json has no usable banner.', self::IRMINSUL_GAME, self::IRMINSUL_FILES[$category]['banners']));
            }

            foreach ($phases as $phase) {
                $codes = [];
                $complete = true;
                foreach ($phase['rate_ups'] as $name) {
                    $matches = $irminsulItems[$category][$name] ?? [];
                    $match = count($matches) === 1 ? $matches[0] : null;
                    if ($match !== null && $match['rarity'] !== null && $match['rarity'] !== self::FEATURED_RARITY) {
                        // Only the featured 6★ count
                        continue;
                    }

                    $itemIds = $match !== null && $match['rarity'] !== null ? ($itemIdsByKey[self::irminsulItemKey($category, $match['id']) ?? ''] ?? []) : [];
                    if (count($itemIds) !== 1) {
                        $complete = false;
                        if (!isset($warned[$category][$name])) {
                            $warned[$category][$name] = true;
                            $this->logger->warning('Endfield irminsul.gg featured item not resolved to an AkeData item', [
                                'category' => $category,
                                'name' => $name,
                                'version' => $phase['version'],
                                'irminsul_id' => $match['id'] ?? null,
                                'reason' => match (true) {
                                    $matches === [] => 'no irminsul item has this name',
                                    $match === null => 'several irminsul items have this name',
                                    $match['rarity'] === null => 'the irminsul item has no rarity',
                                    $itemIds === [] => 'no AkeData item has this id',
                                    default => 'several AkeData items have this id',
                                },
                            ]);
                        }
                        continue;
                    }
                    $codes[] = $itemIds[0];
                }

                $banners[] = [
                    'category' => $category,
                    'version' => $phase['version'],
                    'title' => $phase['title'],
                    'is_re_factor' => $phase['title'] !== null && stripos($phase['title'], 'RE-Factor') !== false,
                    'start_at' => self::toUtc($phase['start']),
                    'end_at' => self::toUtc($phase['end']),
                    'codes' => array_values(array_unique($codes)),
                    'complete' => $complete,
                ];
            }
        }

        return $banners;
    }

    /**
     * The one rerun pool of the banner category whose AkeData rate-ups are all among the featured 6★.
     *
     * @param array{category: string, version: string, codes: list<string>} $banner
     * @param array<string, array{id: string, category: string, type: int, up: list<string>}> $pools
     */
    private function findRerunPool(array $banner, array $pools): ?string
    {
        $candidates = [];
        foreach ($pools as $poolId => $pool) {
            if ($pool['category'] === $banner['category'] && $pool['type'] === self::RERUN_POOL_TYPES[$banner['category']]
                && $pool['up'] !== [] && array_diff($pool['up'], $banner['codes']) === []) {
                $candidates[] = (string) $poolId;
            }
        }

        if (count($candidates) !== 1) {
            $this->logger->warning(sprintf('Endfield irminsul.gg RE-Factor banner skipped: %s', $candidates === [] ? 'no rerun pool features its items' : 'several rerun pools feature its items'), [
                'category' => $banner['category'],
                'version' => $banner['version'],
                'featured' => $banner['codes'],
                'candidates' => $candidates,
            ]);

            return null;
        }

        return $candidates[0];
    }

    /**
     * Candidate limited pools of a regular banner, among the pools whose AkeData rate-ups are all among its featured
     * 6★: the one named after its exact version ("1.2.2" => "joint_1_2_2") when there is one, else every pool of its
     * major.minor. The banner maps to a single candidate; the caller decides what several or none mean.
     *
     * @param array{category: string, version: string, codes: list<string>} $banner
     * @param array<string, array{id: string, category: string, type: int, up: list<string>}> $pools
     *
     * @return list<string>
     */
    private function findFeaturedPoolCandidates(array $banner, array $pools): array
    {
        $versionParts = explode('.', $banner['version']);
        $exact = [];
        $sameMinor = [];
        foreach ($pools as $poolId => $pool) {
            $poolId = (string) $poolId;
            if ($pool['category'] !== $banner['category'] || in_array($pool['type'], self::NON_LIMITED_POOL_TYPES[$banner['category']], true)
                || str_contains($poolId, 'constant') || $pool['up'] === [] || array_diff($pool['up'], $banner['codes']) !== []
                || !preg_match(self::VERSION_POOL_PATTERNS[$banner['category']], $poolId, $matches)
                || [$matches[1], $matches[2]] !== array_slice($versionParts, 0, 2)) {
                continue;
            }

            $sameMinor[] = $poolId;
            if ([$matches[1], $matches[2], $matches[3]] === $versionParts) {
                $exact[] = $poolId;
            }
        }

        return count($exact) === 1 ? $exact : $sameMinor;
    }

    /**
     * A regular weapon banner featuring several 6★, which may own one pool per featured weapon.
     *
     * @param array{category: string, is_re_factor: bool, codes: list<string>} $banner
     */
    private static function isMultiWeaponBanner(array $banner): bool
    {
        return $banner['category'] === AkeCatalog::TYPE_WEAPON && !$banner['is_re_factor'] && count($banner['codes']) >= 2;
    }

    /**
     * @param array{category: string, version: string, codes: list<string>} $banner
     * @param list<string>                                                  $candidates
     */
    private function warnBannerSkipped(array $banner, array $candidates, string $reason): void
    {
        $this->logger->warning(sprintf('Endfield irminsul.gg banner skipped: %s', $reason), [
            'category' => $banner['category'],
            'version' => $banner['version'],
            'featured' => $banner['codes'],
            'candidates' => $candidates,
        ]);
    }

    /**
     * Normal weapon pools of the banner major.minor, not mapped to any banner, whose AkeData rate-ups are all
     * among the featured 6★ of the banner.
     *
     * @param array{version: string, codes: list<string>} $banner
     * @param array<string, array{id: string, category: string, type: int, up: list<string>}> $pools
     * @param array<string, list<int>> $poolBanners
     *
     * @return list<string>
     */
    private function findSiblingWeaponPools(array $banner, array $pools, array $poolBanners): array
    {
        $versionParts = explode('.', $banner['version']);
        if (count($versionParts) < 2) {
            return [];
        }

        $siblings = [];
        foreach ($pools as $poolId => $pool) {
            $poolId = (string) $poolId;
            if (isset($poolBanners[$poolId]) || $pool['category'] !== AkeCatalog::TYPE_WEAPON || $pool['type'] !== AkeCatalog::WEAPON_POOL_TYPE_NORMAL
                || $pool['up'] === [] || array_diff($pool['up'], $banner['codes']) !== []
                || str_contains($poolId, 'constant') || !preg_match(self::VERSION_POOL_PATTERNS[AkeCatalog::TYPE_WEAPON], $poolId, $matches)
                || [$matches[1], $matches[2]] !== [$versionParts[0], $versionParts[1]]) {
                continue;
            }
            $siblings[] = $poolId;
        }

        return $siblings;
    }

    /**
     * Featured 6★ of a banner that belong to one of its pools: all of them when the banner has a single pool;
     * when it owns several (one per featured weapon), those the AkeData list of the pool names, else (AkeData
     * list wrong) those no other pool of the banner names.
     *
     * @param list<string>                $codes
     * @param list<string>                $bannerPoolIds
     * @param array<string, list<string>> $akeDataUps    AkeData rate-ups by pool id
     *
     * @return list<string>
     */
    private static function featuredOfPool(array $codes, string $poolId, array $bannerPoolIds, array $akeDataUps): array
    {
        if (count($bannerPoolIds) < 2) {
            return $codes;
        }

        $own = array_values(array_intersect($codes, $akeDataUps[$poolId]));
        if ($own !== []) {
            return $own;
        }

        $others = [];
        foreach ($bannerPoolIds as $otherPoolId) {
            if ($otherPoolId !== $poolId) {
                array_push($others, ...array_intersect($codes, $akeDataUps[$otherPoolId]));
            }
        }
        $rest = array_values(array_diff($codes, $others));

        return $rest !== [] ? $rest : $codes;
    }

    /**
     * Runs, and irminsul rate-ups, of the previous catalog for the same pool ids (the AkeData fields stay fresh).
     *
     * @param array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}> $pools
     *
     * @return array<string, array{id: string, category: string, type: int, up: list<string>, up_source: string, runs: list<array{start_at: string, end_at: string}>}>
     */
    private function keepPreviousIrminsulFields(array $pools): array
    {
        foreach ($pools as $poolId => $pool) {
            $previous = $this->previousCatalog->findPool((string) $poolId);
            if ($previous === null || $previous['category'] !== $pool['category']) {
                continue;
            }

            $pools[$poolId]['runs'] = $previous['runs'];
            if ($previous['up_source'] === AkeCatalog::UP_SOURCE_IRMINSUL && $previous['up'] !== []) {
                $pools[$poolId]['up'] = $previous['up'];
                $pools[$poolId]['up_source'] = AkeCatalog::UP_SOURCE_IRMINSUL;
            }
        }

        return $pools;
    }

    /**
     * Key shared by an AkeData item id and the irminsul id it matches: "chr_0013_aglina" => "char:0013",
     * "wpn_funnel_0011" => "weapon:funnel:0011"; null for other ids.
     */
    private static function akeDataItemKey(string $itemId): ?string
    {
        if (preg_match('/^chr_(\d{4})_/', $itemId, $matches)) {
            return 'char:' . $matches[1];
        }
        if (preg_match('/^wpn_([a-z]+)_(\d{4})(?:_|$)/', $itemId, $matches)) {
            return sprintf('weapon:%s:%s', $matches[1], $matches[2]);
        }

        return null;
    }

    /**
     * Key of an irminsul item id: operator 10NN => "char:00NN", weapon T*10000+NN => "weapon:<type>:00NN";
     * null when the id does not follow these patterns.
     */
    private static function irminsulItemKey(string $category, int $irminsulId): ?string
    {
        if ($category === AkeCatalog::TYPE_CHAR) {
            return $irminsulId >= 1000 && $irminsulId <= 1999 ? sprintf('char:%04d', $irminsulId - 1000) : null;
        }

        $type = self::IRMINSUL_WEAPON_TYPES[intdiv($irminsulId, 10000)] ?? null;

        return $type !== null ? sprintf('weapon:%s:%04d', $type, $irminsulId % 10000) : null;
    }

    /**
     * @param array{at: string, offset: ?int} $time
     */
    private static function toUtc(array $time): string
    {
        return IrminsulPhaseNormalizer::toInstant($time)->setTimezone(new \DateTimeZone('UTC'))->format(AkeCatalog::RUN_DATE_FORMAT);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function fetchJson(string $path): array
    {
        try {
            // toArray() decodes big integers as strings and fails on HTTP errors or invalid JSON
            return $this->httpClient->request('GET', '/' . $path)->toArray();
        } catch (HttpClientExceptionInterface $e) {
            throw new \RuntimeException(sprintf('AkeData file %s could not be read: %s', $path, $e->getMessage()), 0, $e);
        }
    }
}
