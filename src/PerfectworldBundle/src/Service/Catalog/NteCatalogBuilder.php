<?php

namespace App\PerfectworldBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulException;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the compact NTE catalog from the Waifus-Grace/NTE_Assets datamine.
 *
 * The repository weighs several GB, so it is never cloned: the commit SHA comes from the GitHub API
 * and every file is downloaded from raw.githubusercontent.com at that SHA, so all tables belong to
 * the same datamine. Any problem (README not "(Global)", required table missing, empty or unusable)
 * throws before anything is saved, so the previous catalog stays in place.
 *
 * The result is merged over the previous catalog: ids are never reused by the game, so entries that
 * a partial datamine no longer contains are kept.
 *
 * The datamine has no banner schedule: the runs of each banner come from the irminsul.gg phases, mapped by
 * their featured S items (a character phase to the boards featuring its characters, an Arc phase to the Arc
 * pools featuring its Arcs). irminsul being unavailable does not abort the build: the runs of the previous
 * catalog are kept. The featured items stay those of the datamine, which agree with irminsul on every
 * mapped banner by construction.
 *
 * Each item also gets its full artwork ("preview_path", for the click-to-zoom preview of the pulls tracker):
 * the GachaIllustrate image when it differs from the item icon, else for an Arc the 1024px version of its icon.
 */
class NteCatalogBuilder
{
    public const string DEFAULT_REF = 'main';

    private const string COMMIT_API_URL = 'https://api.github.com/repos/Waifus-Grace/NTE_Assets/commits/%s';
    private const string README_PATH = 'README.md';
    private const string LOCALIZATION_PATH = 'Localization/en/game.json';
    private const string AVATAR_TABLE_PATH = 'DataTable/Avarta/AvatarDataTable.json';
    private const string ARC_POOL_TABLE_PATH = 'DataTable/Fork/DT_ForkLotteryPoolData.json';
    private const string BOARD_CELL_TABLE_PATH = 'DataTable/Monopoly/DT_MonopolyCellDataTable.json';
    private const string GACHA_ILLUSTRATE_TABLE_PATH = 'DataTable/Gacha/GachaIllustrate.json';

    /** 256px Arc icon ("UI_Icon/Fork/fork_HugVine_256.png"), whose 1024px version is "UI_Icon/Fork/1024/fork_HugVine.png". */
    private const string ARC_ICON_PATTERN = '#^UI_Icon/Fork/([A-Za-z0-9_-]+)_256\.png$#';

    /** Game slug of the irminsul.gg data API. */
    private const string IRMINSUL_GAME = 'nte';

    /** irminsul.gg rarity of the S items. */
    private const int IRMINSUL_RARITY_S = 5;

    /**
     * Item tables in precedence order: the first table defining an id sets its type, later tables only
     * fill the fields still missing (e.g. DT_AppearanceData has no icon for gliders, DT_ItemConfig has).
     * GachaIllustrate is not one of them: it is a gacha portrait table without names or qualities that repeats
     * characters, Arcs, dice and cosmetics with their illustrations, read afterwards for the previews only.
     */
    private const array ITEM_TABLES = [
        ['path' => 'DataTable/Character/DT_Character.json', 'type' => NteCatalog::TYPE_CHARACTER, 'required' => true],
        ['path' => 'DataTable/Fork/DT_ForkItemData.json', 'type' => NteCatalog::TYPE_ARC, 'required' => true],
        ['path' => 'DataTable/Inventory/DT_ItemConfig.json', 'type' => NteCatalog::TYPE_ITEM, 'required' => true],
        ['path' => 'DataTable/Inventory/DT_CapitalItemConfig.json', 'type' => NteCatalog::TYPE_ITEM, 'required' => false],
        ['path' => 'DataTable/Vehicle/DT_VehicleItemData.json', 'type' => NteCatalog::TYPE_COSMETIC, 'required' => false],
        ['path' => 'DataTable/Character/Appearance/DT_AppearanceData.json', 'type' => NteCatalog::TYPE_COSMETIC, 'required' => false],
    ];

    /** Item types of the generic item tables that are cosmetics (outfits, gliders, liveries). */
    private const array COSMETIC_ITEM_TYPES = [
        'ITEM_TYPE_FASHION',
        'ITEM_TYPE_GLIDE',
        'ITEM_TYPE_VEHICLE_SKIN',
    ];

    private const array QUALITY_TO_RANK = [
        'ITEM_QUALITY_ORANGE' => 'S',
        'ITEM_QUALITY_PURPLE' => 'A',
        'ITEM_QUALITY_BLUE' => 'B',
    ];

    /** Row fields holding the name, quality and icon (StaticItemData first, StaticAppearanceData second). */
    private const array NAME_FIELDS = ['ItemName', 'Name'];
    private const array QUALITY_FIELDS = ['ItemQuality', 'Quality'];
    private const array ICON_FIELDS = ['ItemIcon', 'ItemIconBig', 'HeadIconBig'];
    private const array AVATAR_ICON_FIELDS = ['icon', 'BigImage'];

    /** Localization keys naming the boards ("Lottery_Kachimingcheng_heiyu" => "Foretold Finale"). */
    private const string BOARD_NAME_KEY_PATTERN = '/^Lottery_Kachimingcheng_(.+)$/i';

    /** Export pool suffixes whose board name key uses a different suffix. */
    private const array BOARD_NAME_KEY_ALIASES = [
        'anhunqu' => 'ahq',
        'permanent' => 'changzhu',
    ];

    /**
     * Localization keys of the board guarantee texts, which name the featured S character
     * ("Guaranteed <Orange>S-Class</> Character <Red>Blackbird</> within roll ×<Blue>{0}</>").
     */
    private const string BOARD_FEATURED_KEY_PATTERN = '/^BPUI_LotteryModuleEntrance_BigGurantten(?:_(.+))?$/i';
    private const string BOARD_FEATURED_NAME_PATTERN = '#<Red>(.+?)</>#i';

    /** Export pool suffixes whose guarantee text key uses a different suffix ('' = the key without suffix). */
    private const array BOARD_FEATURED_KEY_ALIASES = [
        'nanali' => '',
        'permanent' => 'changzhu',
    ];

    public function __construct(
        #[Target('perfectworld.nte_assets.client')]
        private readonly HttpClientInterface $httpClient,
        private readonly NteCatalog $catalog,
        private readonly IrminsulClient $irminsulClient,
        private readonly IrminsulPhaseNormalizer $phaseNormalizer,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds the catalog at the given ref (branch, tag or commit) and merges it over the stored one.
     * The result is not saved: callers persist it with NteCatalog::save().
     *
     * @return array{sha: string, version: string, built_at: string, items: array<string, array{id: string, name: string, rank: ?string, type: string, icon_path: ?string, preview_path?: ?string}>, avatars: array<string, string>, pools: array<string, string>, banners: array<string, array{id: string, category: string, name: ?string, up: list<string>, others: list<string>, up_guarantee: ?int, up_source: string, runs: list<array{start_at: string, end_at: string}>}>}
     *
     * @throws \RuntimeException when the build is aborted (the previous catalog must be kept)
     */
    public function build(?string $ref = null): array
    {
        $sha = $this->resolveSha($ref !== null && trim($ref) !== '' ? trim($ref) : self::DEFAULT_REF);
        $version = $this->fetchGlobalVersion($sha);

        $localization = $this->fetchJson($sha, self::LOCALIZATION_PATH);
        if (!is_array($localization) || $localization === []) {
            throw new \RuntimeException(sprintf('NTE_Assets %s is missing or empty at %s.', self::LOCALIZATION_PATH, $sha));
        }

        $items = [];
        // Lowercased name => ids of the S characters that can be pulled, to resolve the featured board characters
        $pullableCharacters = [];
        foreach (self::ITEM_TABLES as $table) {
            $rows = $this->fetchTableRows($sha, $table['path'], $table['required']);
            if ($rows === null) {
                continue;
            }

            $usable = 0;
            foreach ($rows as $rowId => $row) {
                if (!is_array($row) || trim((string) $rowId) === '') {
                    continue;
                }
                $usable += $this->addItem($items, (string) $rowId, $this->resolveItemType($table['type'], (string) $rowId, $row), $row, $localization) ? 1 : 0;
                if ($table['type'] === NteCatalog::TYPE_CHARACTER) {
                    $this->addPullableCharacter($pullableCharacters, (string) $rowId, $row, $localization);
                }
            }

            if ($table['required'] && $usable === 0) {
                throw new \RuntimeException(sprintf('NTE_Assets table %s yielded no catalog entries at %s.', $table['path'], $sha));
            }
            unset($rows);
        }

        // Entries without any usable name are dropped: callers store the name in a NOT NULL column
        $items = array_filter($items, static fn (array $entry): bool => $entry['name'] !== null);
        // Once every table filled the icons: a preview only counts when it differs from the final icon
        $items = $this->addPreviewPaths($sha, $items);

        $avatars = $this->buildAvatars($sha);

        // Read once: it names the Arc pools and lists their featured Arcs
        $arcPoolRows = $this->fetchTableRows($sha, self::ARC_POOL_TABLE_PATH, false) ?? [];
        $pools = $this->buildPools($arcPoolRows, $localization);
        $banners = array_replace(
            $this->buildArcBanners($arcPoolRows, $pools),
            $this->buildBoardBanners($sha, $localization, $pools, $pullableCharacters),
        );
        unset($localization, $arcPoolRows);

        $previous = $this->catalog->all();
        $mergedItems = array_replace($previous['items'], $items);
        $catalog = [
            'sha' => $sha,
            'version' => $version,
            'built_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'items' => $mergedItems,
            'avatars' => array_replace($previous['avatars'], $avatars),
            'pools' => array_replace($previous['pools'], $pools),
            // Ended banners kept from the previous catalog get their runs too
            'banners' => $this->addBannerRuns(array_replace($previous['banners'], $banners), $mergedItems),
        ];

        $this->logger->info('NTE catalog built', [
            'sha' => $sha,
            'version' => $version,
            'items' => count($items),
            'merged_items' => count($catalog['items']),
            'avatars' => count($avatars),
            'pools' => count($pools),
            'banners' => count($banners),
            'banner_runs' => array_sum(array_map(static fn (mixed $banner): int => is_array($banner) ? count($banner['runs'] ?? []) : 0, $catalog['banners'])),
        ]);

        return $catalog;
    }

    /**
     * Sets the runs of every banner from irminsul.gg (recomputed from the whole history, never appended), or
     * keeps those of the previous catalog when irminsul cannot be read.
     *
     * @param array<string, mixed> $banners merged banners, by lowercased pool id
     * @param array<string, array<string, mixed>> $items merged catalog items
     *
     * @return array<string, mixed>
     */
    private function addBannerRuns(array $banners, array $items): array
    {
        try {
            $runs = $this->fetchBannerRuns($banners, $items);
        } catch (IrminsulException $e) {
            $this->logger->warning('irminsul.gg banners unavailable, NTE banner runs kept from the previous catalog', ['error' => $e->getMessage()]);
            $runs = null;
        }

        foreach ($banners as $key => $banner) {
            if (!is_array($banner)) {
                continue;
            }

            $banners[$key] = array_merge($banner, [
                // NTE_Assets decides the featured items, irminsul agrees with it on every mapped banner by construction
                'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
                'runs' => $runs !== null ? ($runs[$key] ?? []) : ($this->catalog->findPool((string) $key)['runs'] ?? []),
            ]);
        }

        return $banners;
    }

    /**
     * Runs of the banners from the irminsul.gg phases, mapped by their featured S items: the S characters of a
     * character phase (by exact irminsul name, whose id is the game id) to the boards featuring them, the S Arcs
     * of an Arc phase (by case-insensitive name among the S Arcs of the catalog) to the Arc pools featuring
     * them. A phase with a rerun features two S, so it adds a run to two banners. Unmatched names are logged.
     *
     * Every file is read before anything is mapped, so a failure leaves all runs to the previous catalog.
     *
     * @param array<string, mixed> $banners merged banners, by lowercased pool id
     * @param array<string, array<string, mixed>> $items merged catalog items
     *
     * @return array<string, list<array{start_at: string, end_at: string}>> by lowercased pool id
     *
     * @throws IrminsulException when a file cannot be read or has no usable phase
     */
    private function fetchBannerRuns(array $banners, array $items): array
    {
        $characters = $this->irminsulClient->fetch(self::IRMINSUL_GAME, IrminsulClient::FILE_CHARACTERS);
        $phasesByCategory = [];
        foreach ([NteCatalog::BANNER_BOARD => IrminsulClient::FILE_BANNER_CHARACTERS, NteCatalog::BANNER_ARC => IrminsulClient::FILE_BANNER_WEAPONS] as $category => $file) {
            $phases = $this->phaseNormalizer->normalize($this->irminsulClient->fetch(self::IRMINSUL_GAME, $file), $category, IrminsulTimeRules::nte(), $this->logger);
            if ($phases === []) {
                throw new IrminsulException(sprintf('irminsul.gg %s/%s.json has no usable phase.', self::IRMINSUL_GAME, $file));
            }
            $phasesByCategory[$category] = $phases;
        }

        // Exact irminsul name => [game id, rarity] of every character entry
        $charactersByName = [];
        foreach ($characters as $character) {
            $id = $character['id'] ?? null;
            $name = $this->nonBlank($character['name'] ?? null);
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && $name !== null) {
                $charactersByName[$name][] = ['id' => (string) $id, 'rarity' => $character['rarity'] ?? null];
            }
        }

        // Lowercased name => ids of the S Arcs of the catalog
        $arcIdsByName = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['type'] ?? null) === NteCatalog::TYPE_ARC && ($item['rank'] ?? null) === 'S' && is_string($item['name'] ?? null) && is_string($item['id'] ?? null)) {
                $arcIdsByName[mb_strtolower(trim($item['name']))][] = $item['id'];
            }
        }

        // Category => lowercased featured item id => lowercased pool ids of the banners featuring it
        $bannersByFeatured = [];
        foreach ($banners as $key => $banner) {
            $category = is_array($banner) ? ($banner['category'] ?? null) : null;
            if (!in_array($category, [NteCatalog::BANNER_ARC, NteCatalog::BANNER_BOARD], true) || !is_array($banner['up'] ?? null)) {
                continue;
            }
            foreach ($banner['up'] as $featuredId) {
                if (is_string($featuredId) && trim($featuredId) !== '') {
                    $bannersByFeatured[$category][NteCatalog::normalizeId($featuredId)][] = (string) $key;
                }
            }
        }

        $runs = [];
        $mappedPhases = 0;
        foreach ($phasesByCategory as $category => $phases) {
            foreach ($phases as $phase) {
                $context = ['category' => $category, 'version' => $phase['version']];
                $phaseBannerKeys = [];
                foreach ($phase['rate_ups'] as $name) {
                    $featuredId = $category === NteCatalog::BANNER_BOARD
                        ? $this->resolveIrminsulCharacter($name, $charactersByName, $context)
                        : $this->resolveIrminsulArc($name, $arcIdsByName, $context);
                    if ($featuredId === null) {
                        continue;
                    }

                    $bannerKeys = $bannersByFeatured[$category][NteCatalog::normalizeId($featuredId)] ?? [];
                    if ($bannerKeys === []) {
                        $this->logger->warning('irminsul.gg NTE rate-up featured by no catalog banner, no run added', $context + ['name' => $name, 'item' => $featuredId]);
                        continue;
                    }
                    array_push($phaseBannerKeys, ...$bannerKeys);
                }

                $run = [
                    'start_at' => self::toUtc($phase['start']),
                    'end_at' => self::toUtc($phase['end']),
                ];
                foreach (array_unique($phaseBannerKeys) as $bannerKey) {
                    $runs[$bannerKey][] = $run;
                }
                $mappedPhases += $phaseBannerKeys !== [] ? 1 : 0;
            }
        }

        $runs = array_map(NteCatalog::normalizeRuns(...), $runs);

        $this->logger->info('NTE banner runs read from irminsul.gg', [
            'phases' => array_sum(array_map('count', $phasesByCategory)),
            'mapped_phases' => $mappedPhases,
            'banners' => count($runs),
            'runs' => array_sum(array_map('count', $runs)),
        ]);

        return $runs;
    }

    /**
     * Game id of the S character an irminsul rate-up names (exact irminsul name); null for an A character, or
     * with a warning for an unknown or ambiguous name.
     *
     * @param array<string, list<array{id: string, rarity: mixed}>> $charactersByName
     * @param array<string, mixed> $context
     */
    private function resolveIrminsulCharacter(string $name, array $charactersByName, array $context): ?string
    {
        $matches = $charactersByName[$name] ?? [];
        if (count($matches) !== 1) {
            $this->logger->warning('irminsul.gg NTE rate-up character not resolved, no run added', $context + ['name' => $name, 'matches' => array_column($matches, 'id')]);

            return null;
        }

        return $matches[0]['rarity'] === self::IRMINSUL_RARITY_S ? $matches[0]['id'] : null;
    }

    /**
     * Id of the catalog S Arc an irminsul rate-up names (case-insensitive exact name), with a warning when no
     * S Arc or several have it.
     *
     * @param array<string, list<string>> $arcIdsByName
     * @param array<string, mixed> $context
     */
    private function resolveIrminsulArc(string $name, array $arcIdsByName, array $context): ?string
    {
        $ids = $arcIdsByName[mb_strtolower(trim($name))] ?? [];
        if (count($ids) !== 1) {
            $this->logger->warning('irminsul.gg NTE rate-up Arc not resolved, no run added', $context + ['name' => $name, 'matches' => $ids]);

            return null;
        }

        return $ids[0];
    }

    /**
     * UTC "Y-m-d H:i:s" of a normalized irminsul time (NTE times carry UTC+8; one without zone is read as UTC+8).
     *
     * @param array{at: string, offset: ?int} $time
     */
    private static function toUtc(array $time): string
    {
        return IrminsulPhaseNormalizer::toInstant($time, new \DateTimeZone('+08:00'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(NteCatalog::RUN_DATE_FORMAT);
    }

    private function resolveSha(string $ref): string
    {
        if (!preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $ref)) {
            throw new \RuntimeException(sprintf('Invalid NTE_Assets ref "%s".', $ref));
        }

        $response = $this->httpClient->request('GET', sprintf(self::COMMIT_API_URL, $ref), [
            'headers' => [
                // Plain-text SHA instead of the full commit payload (datamine commits list thousands of files)
                'Accept' => 'application/vnd.github.sha',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        $sha = strtolower(trim($response->getContent()));
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new \RuntimeException(sprintf('Unexpected GitHub answer while resolving NTE_Assets ref "%s".', $ref));
        }

        return $sha;
    }

    /**
     * The README states the datamined build ("> Version: 1.5.4 (Global)"): only global datamines match
     * the ids and names of the exports, so anything else aborts the build.
     */
    private function fetchGlobalVersion(string $sha): string
    {
        $readme = $this->fetchFile($sha, self::README_PATH);
        if ($readme === null) {
            throw new \RuntimeException(sprintf('NTE_Assets README is missing at %s.', $sha));
        }

        if (!preg_match('/Version:\s*([^\s(]+)\s*\(Global\)/i', $readme, $matches)) {
            throw new \RuntimeException(sprintf('NTE_Assets README at %s does not describe a Global datamine.', $sha));
        }

        return $matches[1];
    }

    /**
     * Adds a row (or fills the missing fields of an entry from a previous table).
     *
     * @param array<string, array{id: string, name: ?string, rank: ?string, type: string, icon_path: ?string, preview_path: ?string}> $items
     * @param array<string, mixed> $row
     * @param array<string, mixed> $localization
     *
     * @return bool whether the entry now has a usable name
     */
    private function addItem(array &$items, string $rowId, string $type, array $row, array $localization): bool
    {
        $entry = [
            'id' => $rowId,
            'name' => $this->resolveText($this->firstField($row, self::NAME_FIELDS), $localization),
            'rank' => $this->resolveRank($this->firstField($row, self::QUALITY_FIELDS)),
            'type' => $type,
            'icon_path' => $this->resolveIconPath($row, self::ICON_FIELDS),
            // Set by addPreviewPaths() once every table is read
            'preview_path' => null,
        ];

        $key = NteCatalog::normalizeId($rowId);
        if (!isset($items[$key])) {
            $items[$key] = $entry;
        } else {
            foreach (['name', 'rank', 'icon_path'] as $field) {
                $items[$key][$field] ??= $entry[$field];
            }
        }

        return $items[$key]['name'] !== null;
    }

    /**
     * Sets the full artwork of every item ("preview_path", null when it has none):
     * - the GachaIllustrate image ("ItemIcon", rows keyed by item id in any case) when it is not the item icon:
     *   the 1024px gacha splash or outfit art of the characters, the illustrations of some cosmetics; for the
     *   Arcs and the dice the table only repeats their icon;
     * - otherwise, for an Arc, the 1024px version of its 256px icon. A few Arcs lack it: that URL answers 404
     *   and the preview falls back to the icon.
     * GachaIllustrate is optional: without it only the Arcs get a preview.
     *
     * @param array<string, array{id: string, name: ?string, rank: ?string, type: string, icon_path: ?string, preview_path: ?string}> $items
     *
     * @return array<string, array{id: string, name: ?string, rank: ?string, type: string, icon_path: ?string, preview_path: ?string}>
     */
    private function addPreviewPaths(string $sha, array $items): array
    {
        $illustrations = [];
        foreach ($this->fetchTableRows($sha, self::GACHA_ILLUSTRATE_TABLE_PATH, false) ?? [] as $rowId => $row) {
            if (!is_array($row) || trim((string) $rowId) === '') {
                continue;
            }
            $path = $this->resolveIconPath($row, ['ItemIcon']);
            if ($path !== null) {
                $illustrations[NteCatalog::normalizeId((string) $rowId)] ??= $path;
            }
        }

        foreach ($items as $key => $item) {
            $illustration = $illustrations[$key] ?? null;
            // Asset paths are case-insensitive in the game: a path differing only in case is the icon itself
            $items[$key]['preview_path'] = $illustration !== null && ($item['icon_path'] === null || strcasecmp($illustration, $item['icon_path']) !== 0)
                ? $illustration
                : $this->resolveArcPreviewPath($item);
        }

        return $items;
    }

    /**
     * "UI_Icon/Fork/fork_HugVine_256.png" => "UI_Icon/Fork/1024/fork_HugVine.png" for an Arc, null for anything else.
     *
     * @param array{type: string, icon_path: ?string} $item
     */
    private function resolveArcPreviewPath(array $item): ?string
    {
        if ($item['type'] !== NteCatalog::TYPE_ARC || $item['icon_path'] === null || !preg_match(self::ARC_ICON_PATTERN, $item['icon_path'], $matches)) {
            return null;
        }

        return sprintf('UI_Icon/Fork/1024/%s.png', $matches[1]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveItemType(string $tableType, string $rowId, array $row): string
    {
        if ($tableType !== NteCatalog::TYPE_ITEM) {
            return $tableType;
        }

        $itemType = is_string($row['ItemType'] ?? null) ? $this->enumValue($row['ItemType']) : '';
        if (str_starts_with(strtolower($rowId), 'fashion_') || in_array($itemType, self::COSMETIC_ITEM_TYPES, true)) {
            return NteCatalog::TYPE_COSMETIC;
        }

        return NteCatalog::TYPE_ITEM;
    }

    /**
     * Collects the S characters that can be pulled ("bIsInvite") by lowercased name: other S rows reuse
     * the same names (three "Lacrimosa" rows, only 1004 can be pulled).
     *
     * @param array<string, list<string>> $characters
     * @param array<string, mixed> $row
     * @param array<string, mixed> $localization
     */
    private function addPullableCharacter(array &$characters, string $rowId, array $row, array $localization): void
    {
        if (($row['bIsInvite'] ?? null) !== true || $this->resolveRank($row['ItemQuality'] ?? null) !== 'S') {
            return;
        }

        $name = $this->resolveText($row['ItemName'] ?? null, $localization);
        if ($name !== null) {
            $characters[mb_strtolower($name)][] = $rowId;
        }
    }

    /**
     * Profile avatars (SDK "avatar" field, e.g. "Fashion_1042_1") live in AvatarDataTable.
     *
     * @return array<string, string>
     */
    private function buildAvatars(string $sha): array
    {
        $rows = $this->fetchTableRows($sha, self::AVATAR_TABLE_PATH, false) ?? [];

        $avatars = [];
        foreach ($rows as $rowId => $row) {
            if (!is_array($row) || trim((string) $rowId) === '') {
                continue;
            }
            $iconPath = $this->resolveIconPath($row, self::AVATAR_ICON_FIELDS);
            if ($iconPath !== null) {
                $avatars[NteCatalog::normalizeId((string) $rowId)] = $iconPath;
            }
        }

        return $avatars;
    }

    /**
     * Arc pools come from DT_ForkLotteryPoolData ("ShowText1", e.g. "Bright Moon Special"); boards have
     * no table, so their names come from the "Lottery_Kachimingcheng_<suffix>" localization keys.
     *
     * @param array<int|string, mixed> $arcPoolRows rows of DT_ForkLotteryPoolData
     * @param array<string, mixed> $localization
     *
     * @return array<string, string>
     */
    private function buildPools(array $arcPoolRows, array $localization): array
    {
        $pools = [];

        foreach ($arcPoolRows as $poolId => $row) {
            if (!$this->isArcPoolRow($poolId, $row)) {
                continue;
            }
            $name = $this->resolveText($row['ShowText1'] ?? null, $localization) ?? $this->resolveText($row['Name'] ?? null, $localization);
            if ($name !== null) {
                $pools[NteCatalog::normalizeId((string) $poolId)] = $name;
            }
        }

        $boardNames = [];
        foreach ($localization as $strings) {
            if (!is_array($strings)) {
                continue;
            }
            foreach ($strings as $key => $value) {
                if (is_string($value) && trim($value) !== '' && preg_match(self::BOARD_NAME_KEY_PATTERN, (string) $key, $matches)) {
                    $boardNames[strtolower($matches[1])] ??= trim($value);
                }
            }
        }

        foreach ($boardNames as $suffix => $name) {
            $pools['lottery_' . $suffix] ??= $name;
        }
        foreach (self::BOARD_NAME_KEY_ALIASES as $poolSuffix => $keySuffix) {
            if (isset($boardNames[$keySuffix])) {
                $pools['lottery_' . $poolSuffix] ??= $boardNames[$keySuffix];
            }
        }

        return $pools;
    }

    /**
     * Arc banners: the rate-up Arc ("UpList", or the "IsUp" show rewards of rows without it), the other S
     * Arcs shown by the banner and the guarantee of the rate-up Arc. The runs are set later, from irminsul.gg.
     *
     * @param array<int|string, mixed> $arcPoolRows rows of DT_ForkLotteryPoolData
     * @param array<string, string> $pools
     *
     * @return array<string, array{id: string, category: string, name: ?string, up: list<string>, others: list<string>, up_guarantee: ?int, up_source: string, runs: list<array{start_at: string, end_at: string}>}>
     */
    private function buildArcBanners(array $arcPoolRows, array $pools): array
    {
        $banners = [];

        foreach ($arcPoolRows as $poolId => $row) {
            if (!$this->isArcPoolRow($poolId, $row)) {
                continue;
            }
            $showRewards = is_array($row['ShowRewards'] ?? null) ? $row['ShowRewards'] : [];
            $guarantee = $row['UpGuaranteeCnt'] ?? null;
            $key = NteCatalog::normalizeId((string) $poolId);

            $banners[$key] = [
                'id' => (string) $poolId,
                'category' => NteCatalog::BANNER_ARC,
                'name' => $pools[$key] ?? null,
                'up' => is_array($row['UpList'] ?? null) ? $this->stringList($row['UpList']) : $this->showRewardIds($showRewards, true),
                'others' => $this->showRewardIds($showRewards, false),
                'up_guarantee' => is_int($guarantee) && $guarantee > 0 ? $guarantee : null,
                'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
                'runs' => [],
            ];
        }

        return $banners;
    }

    /**
     * Board banners. Boards have no pool table: the pools are the "PoolDropDatas" keys of the board cells,
     * and the featured S character is only named by the guarantee text of each board. The runs are set
     * later, from irminsul.gg.
     *
     * @param array<string, mixed> $localization
     * @param array<string, string> $pools
     * @param array<string, list<string>> $pullableCharacters lowercased name => S character ids
     *
     * @return array<string, array{id: string, category: string, name: ?string, up: list<string>, others: list<string>, up_guarantee: ?int, up_source: string, runs: list<array{start_at: string, end_at: string}>}>
     */
    private function buildBoardBanners(string $sha, array $localization, array $pools, array $pullableCharacters): array
    {
        $poolIds = [];
        foreach ($this->fetchTableRows($sha, self::BOARD_CELL_TABLE_PATH, false) ?? [] as $cell) {
            if (!is_array($cell) || !is_array($cell['PoolDropDatas'] ?? null)) {
                continue;
            }
            foreach ($cell['PoolDropDatas'] as $poolDrop) {
                $poolId = is_array($poolDrop) ? $this->nonBlank($poolDrop['Key'] ?? null) : null;
                if ($poolId !== null) {
                    $poolIds[NteCatalog::normalizeId($poolId)] ??= $poolId;
                }
            }
        }

        if ($poolIds === []) {
            return [];
        }

        $featuredNames = $this->findBoardFeaturedNames($localization);

        $banners = [];
        foreach ($poolIds as $key => $poolId) {
            $key = (string) $key;
            $suffix = str_starts_with($key, 'lottery_') ? substr($key, strlen('lottery_')) : $key;
            $keySuffix = array_key_exists($suffix, $featuredNames) ? $suffix : (self::BOARD_FEATURED_KEY_ALIASES[$suffix] ?? $suffix);
            $featuredName = $featuredNames[$keySuffix] ?? null;
            if ($featuredName === null && $key !== NteCatalog::normalizeId(NevernessToEvernessService::BANNER_STANDARD)) {
                // A limited board whose guarantee text key changed: flag it rather than lose its featured character silently
                $this->logger->warning('No featured character text for an NTE limited board, banner kept without it', ['pool' => $poolId]);
            }

            $banners[$key] = [
                'id' => $poolId,
                'category' => NteCatalog::BANNER_BOARD,
                'name' => $pools[$key] ?? null,
                'up' => $featuredName !== null ? $this->resolveFeaturedCharacter($poolId, $featuredName, $pullableCharacters) : [],
                'others' => [],
                'up_guarantee' => null,
                'up_source' => NteCatalog::UP_SOURCE_NTE_ASSETS,
                'runs' => [],
            ];
        }

        return $banners;
    }

    /**
     * Featured S character named by each board guarantee text ("… Character <Red>Blackbird</> …"), by
     * lowercased key suffix ('' for the key without suffix); null when the text names none (standard
     * board). The same key may repeat in another namespace: the first non-blank text wins.
     *
     * @param array<string, mixed> $localization
     *
     * @return array<string, ?string>
     */
    private function findBoardFeaturedNames(array $localization): array
    {
        $texts = [];
        foreach ($localization as $strings) {
            if (!is_array($strings)) {
                continue;
            }
            foreach ($strings as $key => $value) {
                if (is_string($value) && trim($value) !== '' && preg_match(self::BOARD_FEATURED_KEY_PATTERN, (string) $key, $matches)) {
                    $texts[strtolower($matches[1] ?? '')] ??= $value;
                }
            }
        }

        return array_map(
            fn (string $text): ?string => preg_match(self::BOARD_FEATURED_NAME_PATTERN, $text, $matches) ? $this->nonBlank($matches[1]) : null,
            $texts,
        );
    }

    /**
     * The featured character is matched by name, so it must match exactly one pullable S character.
     *
     * @param array<string, list<string>> $pullableCharacters
     *
     * @return list<string>
     */
    private function resolveFeaturedCharacter(string $poolId, string $name, array $pullableCharacters): array
    {
        $ids = $pullableCharacters[mb_strtolower($name)] ?? [];
        if (count($ids) === 1) {
            return $ids;
        }

        $this->logger->warning('Featured character of an NTE board not resolved, banner kept without it', [
            'pool' => $poolId,
            'name' => $name,
            'matches' => $ids,
        ]);

        return [];
    }

    /**
     * Rotation rows of DT_ForkLotteryPoolData ("ForkLottery_<suffix>"); row "1" repeats the launch pool.
     *
     * @phpstan-assert-if-true array<string, mixed> $row
     */
    private function isArcPoolRow(int|string $poolId, mixed $row): bool
    {
        return is_array($row) && str_starts_with(strtolower((string) $poolId), 'forklottery_');
    }

    /**
     * Item ids of the banner show rewards with the given "IsUp" flag.
     *
     * @param array<int|string, mixed> $showRewards
     *
     * @return list<string>
     */
    private function showRewardIds(array $showRewards, bool $isUp): array
    {
        $ids = [];
        foreach ($showRewards as $reward) {
            if (is_array($reward) && ($reward['IsUp'] ?? null) === $isUp) {
                $ids[] = $reward['ItemID'] ?? null;
            }
        }

        return $this->stringList($ids);
    }

    /**
     * Distinct non-blank strings of a list, trimmed.
     *
     * @param array<int|string, mixed> $values
     *
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $value = $this->nonBlank($value);
            if ($value !== null && !in_array($value, $strings, true)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Resolves an FText through the English localization: the embedded LocalizedString is from an older
     * build, so the namespace + key lookup comes first, then the key in any namespace (some keys live
     * outside the namespace of their TableId), then the embedded string. Blank values count as missing.
     *
     * @param array<string, mixed> $localization
     */
    private function resolveText(mixed $text, array $localization): ?string
    {
        if (!is_array($text)) {
            return null;
        }

        $key = is_string($text['Key'] ?? null) ? $text['Key'] : '';
        if ($key !== '') {
            $namespace = $this->namespaceFromTableId(is_string($text['TableId'] ?? null) ? $text['TableId'] : '');
            $value = $this->nonBlank($localization[$namespace][$key] ?? null);
            if ($value !== null) {
                return $value;
            }

            foreach ($localization as $strings) {
                if (is_array($strings) && ($value = $this->nonBlank($strings[$key] ?? null)) !== null) {
                    return $value;
                }
            }
        }

        return $this->nonBlank($text['LocalizedString'] ?? null);
    }

    /**
     * "/Game/Text/ST_Player.ST_Player" => "ST_Player".
     */
    private function namespaceFromTableId(string $tableId): string
    {
        if ($tableId === '') {
            return '';
        }

        $dot = strrpos($tableId, '.');
        if ($dot !== false) {
            return substr($tableId, $dot + 1);
        }

        $slash = strrpos($tableId, '/');

        return $slash !== false ? substr($tableId, $slash + 1) : $tableId;
    }

    private function resolveRank(mixed $quality): ?string
    {
        return is_string($quality) ? (self::QUALITY_TO_RANK[$this->enumValue($quality)] ?? null) : null;
    }

    /**
     * "/Game/UI/UI_Icon/Fork/fork_HugVine_256.fork_HugVine_256" => "UI_Icon/Fork/fork_HugVine_256.png".
     * The object name after the dot may differ in case from the file name, so only the path is kept.
     *
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private function resolveIconPath(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            $assetPath = is_array($row[$field] ?? null) ? ($row[$field]['AssetPathName'] ?? null) : null;
            if (!is_string($assetPath) || !str_starts_with($assetPath, '/Game/UI/')) {
                continue;
            }

            $path = explode('.', substr($assetPath, strlen('/Game/UI/')), 2)[0];
            if ($path !== '' && preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $path)) {
                return $path . '.png';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private function firstField(array $row, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                return $row[$field];
            }
        }

        return null;
    }

    /**
     * "EItemQuality::ITEM_QUALITY_ORANGE" => "ITEM_QUALITY_ORANGE".
     */
    private function enumValue(string $value): string
    {
        $separator = strrpos($value, '::');

        return $separator !== false ? substr($value, $separator + 2) : $value;
    }

    private function nonBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Rows of an exported DataTable ([{"Type": "DataTable", "Rows": {...}}]).
     *
     * @return array<int|string, mixed>|null null when an optional table is missing
     */
    private function fetchTableRows(string $sha, string $path, bool $required): ?array
    {
        $data = $this->fetchJson($sha, $path);

        $rows = null;
        if (is_array($data)) {
            foreach (array_is_list($data) ? $data : [$data] as $export) {
                if (is_array($export) && is_array($export['Rows'] ?? null)) {
                    $rows = $export['Rows'];
                    break;
                }
            }
        }

        if ($rows === null || $rows === []) {
            if ($required) {
                throw new \RuntimeException(sprintf('NTE_Assets table %s is missing or empty at %s.', $path, $sha));
            }

            $this->logger->warning('Optional NTE_Assets table missing or empty, skipped', ['path' => $path, 'sha' => $sha]);

            return null;
        }

        return $rows;
    }

    /**
     * raw.githubusercontent.com serves JSON as text/plain, so the body is decoded here.
     *
     * @return array<int|string, mixed>|null null when the file does not exist
     */
    private function fetchJson(string $sha, string $path): ?array
    {
        $content = $this->fetchFile($sha, $path);
        if ($content === null) {
            return null;
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('NTE_Assets file %s at %s is not valid JSON: %s', $path, $sha, $e->getMessage()), 0, $e);
        }

        return is_array($data) ? $data : null;
    }

    private function fetchFile(string $sha, string $path): ?string
    {
        $response = $this->httpClient->request('GET', sprintf('%s/%s/%s', NteCatalog::RAW_BASE_URL, $sha, $path));
        if ($response->getStatusCode() === 404) {
            return null;
        }

        return $response->getContent();
    }
}
