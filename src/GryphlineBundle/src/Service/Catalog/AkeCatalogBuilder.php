<?php

namespace App\GryphlineBundle\Service\Catalog;

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
 */
class AkeCatalogBuilder
{
    private const string MANIFEST_PATH = 'manifest.json';
    private const string ITEM_TABLE_FILE = 'ItemTable.json';
    private const string TEXT_TABLE_FILE = 'I18nTextTable_EN.json';

    /** Pool table file and field holding the rate-up item ids, per catalog type. */
    private const array POOL_TABLES = [
        AkeCatalog::TYPE_CHAR => ['file' => 'GachaCharPoolTable.json', 'up_field' => 'upCharIds'],
        AkeCatalog::TYPE_WEAPON => ['file' => 'GachaWeaponPoolTable.json', 'up_field' => 'upWeaponIds'],
    ];

    /** Relative TableCfg directory published by the manifest (e.g. "public/1.6.4/11944529-13/TableCfg"). */
    private const string TABLE_CFG_PATH_PATTERN = '#^[A-Za-z0-9_@-][A-Za-z0-9._@-]*(/[A-Za-z0-9_@-][A-Za-z0-9._@-]*)*$#';

    public function __construct(
        #[Target('gryphline.akedata.client')]
        private readonly HttpClientInterface $httpClient,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds the catalog of the given manifest version id ("latest" when null). The result is not saved:
     * callers persist it with AkeCatalog::save().
     *
     * @return array{version: string, built_at: string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>}>}
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

        $this->logger->info('Endfield catalog built', [
            'version' => $versionId,
            'items' => count($items),
            'unresolved' => count($candidates) - count($items),
            'char_pools' => $poolCounts[AkeCatalog::TYPE_CHAR],
            'weapon_pools' => $poolCounts[AkeCatalog::TYPE_WEAPON],
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
     * @return array<string, array{id: string, category: string, type: int, up: list<string>}>
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
