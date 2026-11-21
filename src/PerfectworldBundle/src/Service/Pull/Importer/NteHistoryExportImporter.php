<?php

namespace App\PerfectworldBundle\Service\Pull\Importer;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Pull\ImportedPullRecord;
use App\PerfectworldBundle\Model\Pull\PullBackupImporterInterface;
use App\PerfectworldBundle\Model\Pull\PullFileInspection;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;

/**
 * Importer of nte-exporter files ("nte-history-export" v1, one file per banner).
 * See https://github.com/RishiMenon2004/nte-exporter (maintained fork of Golumpa/nte-exporter).
 *
 * Board files are validated group by group (records sharing the same second): a 10-roll is 10 rolls
 * plus the Points Gift earned on the 10th cumulative roll. Exporters older than 0.3.1 label many rolls
 * as "points_gift"; their labels are rebuilt from that rule (see repairOldLabels()).
 */
class NteHistoryExportImporter implements PullBackupImporterInterface
{
    public const string FORMAT = 'nte-history-export';
    public const int FORMAT_VERSION = 1;
    private const string FIRST_FIXED_VERSION = '0.3.1';
    private const string MYSTERY_BOX_BANNER = 'Gashapon_MysteryBox';
    private const string REPAIR_FAILED_MESSAGE = 'Old export could not be repaired: re-export it with an updated nte-exporter.';

    /** Allowed group sizes once Slumberland records are removed: single roll, roll + gift, 10 rolls + gift. */
    private const array BOARD_GROUP_SIZES = [1, 2, 11];

    private const array RANK_TO_RARITY = [
        'S' => NevernessToEvernessService::RARITY_S,
        'A' => NevernessToEvernessService::RARITY_A,
        'B' => NevernessToEvernessService::RARITY_B,
    ];

    public function __construct(
        private readonly NevernessToEvernessService $game,
        private readonly NteCatalog $catalog,
    ) {
    }

    public static function getKey(): string
    {
        return 'nte_exporter';
    }

    public function getLabel(): string
    {
        return 'nte-exporter (JSON)';
    }

    public function getDescription(): string
    {
        return 'Export your history with nte-exporter (https://github.com/RishiMenon2004/nte-exporter) and upload the JSON files of the Limited, Standard and Arc banners (one file per banner, up to 3 at once). Files from exporter 0.3.0 are repaired automatically.';
    }

    public function getAcceptedExtensions(): array
    {
        return ['.json'];
    }

    public static function getSupportedGameServices(): array
    {
        return [NevernessToEvernessService::class];
    }

    public function getMaxFiles(): int
    {
        return 3;
    }

    public function inspect(string $filePath): PullFileInspection
    {
        $data = $this->readFile($filePath);

        return new PullFileInspection(
            gameUid: isset($data['user_uid']) ? (string) $data['user_uid'] : null,
            exporterVersion: (string) $data['exporter']['version'],
            banners: [(string) $data['banner']['id'] => [
                'name' => $this->getBannerName((string) $data['banner']['id']),
                'count' => count($data['records']),
            ]],
        );
    }

    public function parse(PerfectworldGameProfile $profile, string $filePath): array
    {
        $data = $this->readFile($filePath);
        $userUid = isset($data['user_uid']) ? (string) $data['user_uid'] : null;
        if ($userUid !== null && $userUid !== $profile->getGameUid()) {
            throw new \InvalidArgumentException(sprintf('This export belongs to another character (UID %s).', $userUid));
        }

        $bannerKey = (string) $data['banner']['id'];
        $version = (string) $data['exporter']['version'];
        $rows = self::withGroupPositions($data['records']);

        $skippedWarning = null;
        if (NevernessToEvernessService::isBoardBanner($bannerKey)) {
            ['kinds' => $kinds, 'warning' => $skippedWarning] = $this->resolveBoardKinds($rows, $version);
        } else {
            $kinds = array_fill(0, count($rows), PerfectworldPull::KIND_DRAW);
        }

        $records = [];
        foreach ($rows as $index => $row) {
            // Rows of an incomplete oldest second of an old export are left out (null kind)
            if ($kinds[$index] === null) {
                continue;
            }
            $record = $this->buildRecord($bannerKey, $version, $row, $kinds[$index]);
            if ($skippedWarning !== null && $record->warning === null) {
                $record = $record->withWarning($skippedWarning);
                $skippedWarning = null;
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Decodes and validates the file header (format, banner, scan completeness).
     *
     * @return array<string, mixed>
     */
    private function readFile(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('Export file is missing or not readable.');
        }

        try {
            $data = json_decode((string) file_get_contents($filePath), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('Invalid JSON: %s', $e->getMessage()));
        }

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('This is not an nte-exporter file (expected "format": "nte-history-export").');
        }
        if ((int) ($data['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException(sprintf('Unsupported nte-exporter format version "%s".', (string) ($data['format_version'] ?? '?')));
        }
        if (!is_string($data['exporter']['version'] ?? null) || $data['exporter']['version'] === '') {
            throw new \InvalidArgumentException('Missing exporter version in the nte-exporter file.');
        }

        $bannerKey = (string) ($data['banner']['id'] ?? '');
        if ($bannerKey === self::MYSTERY_BOX_BANNER) {
            throw new \InvalidArgumentException('Mystery Box exports are not supported.');
        }
        if (!isset($this->game->getGachaConfig()->getBannerGroups('categories')[$bannerKey])) {
            throw new \InvalidArgumentException(sprintf('Unsupported banner "%s".', $bannerKey));
        }

        if (!is_array($data['records'] ?? null) || $data['records'] === []) {
            throw new \InvalidArgumentException('No records found in this export.');
        }
        foreach ($data['records'] as $record) {
            if (!is_array($record) || !isset($record['timestamp'], $record['reward_id'])) {
                throw new \InvalidArgumentException('Malformed record in the nte-exporter file.');
            }
        }

        $this->assertCompleteScan($data);

        return $data;
    }

    /**
     * Positions within a second are only stable when the scan started at page 1 and kept every record.
     *
     * @param array<string, mixed> $data
     */
    private function assertCompleteScan(array $data): void
    {
        $scan = is_array($data['scan'] ?? null) ? $data['scan'] : null;
        $pages = is_array($scan['pages_seen'] ?? null) ? array_map('intval', $scan['pages_seen']) : [];
        if ($scan === null || $pages === [] || $pages !== range(1, count($pages))) {
            throw new \InvalidArgumentException('Incomplete export: the history scan must start at page 1 without gaps.');
        }

        foreach ((array) ($scan['warnings'] ?? []) as $warning) {
            if (str_contains(is_array($warning) ? (string) json_encode($warning) : (string) $warning, 'DID_NOT_START_AT_PAGE_1')) {
                throw new \InvalidArgumentException('Incomplete export: the history scan did not start at page 1.');
            }
        }

        if ((int) ($scan['skipped_records'] ?? 0) !== 0 || (int) ($scan['exported_records'] ?? -1) !== count($data['records'])) {
            throw new \InvalidArgumentException('Incomplete export: some history records were skipped by the exporter.');
        }
    }

    /**
     * Adds to each record its position among the records of the same second (0 = most recent).
     * timestamp_group_ordinal is not used: it restarts inside groups split across pages.
     *
     * @param list<array<string, mixed>> $records Newest first, as exported
     * @return list<array{timestamp: string, position: int, record: array<string, mixed>}>
     */
    private static function withGroupPositions(array $records): array
    {
        $positions = [];
        $rows = [];
        foreach ($records as $record) {
            $timestamp = (string) $record['timestamp'];
            $positions[$timestamp] = ($positions[$timestamp] ?? -1) + 1;
            $rows[] = ['timestamp' => $timestamp, 'position' => $positions[$timestamp], 'record' => $record];
        }

        return $rows;
    }

    /**
     * The oldest second of a file may be cut, by a scan stopped before the end of the history or by the
     * game's ~6-month history window: it then holds only the most recent records of that second.
     * - Fixed exporters (>= 0.3.1): labels are trusted and positions stay stable, so the oldest second is
     *   imported as is, without the group checks.
     * - Old exporters: labels are rebuilt from complete groups only, so an incomplete oldest second is
     *   left out (a later, longer export brings it back).
     *
     * @param list<array{timestamp: string, position: int, record: array<string, mixed>}> $rows
     * @return array{kinds: array<int, ?string>, warning: ?string} Kind of each row (null = left out)
     */
    private function resolveBoardKinds(array $rows, string $version): array
    {
        $kinds = [];
        foreach ($rows as $index => $row) {
            $kinds[$index] = match ($row['record']['result_type'] ?? 'dice') {
                'chase_reward' => PerfectworldPull::KIND_SLUMBERLAND,
                'points_gift' => PerfectworldPull::KIND_POINTS_GIFT,
                default => PerfectworldPull::KIND_ROLL,
            };
        }

        // Groups of non-Slumberland row indexes, newest group first and newest row first
        $groups = [];
        foreach ($rows as $index => $row) {
            if ($kinds[$index] === PerfectworldPull::KIND_SLUMBERLAND) {
                continue;
            }
            $groups[$row['timestamp']][] = $index;
        }

        $oldestTimestamp = array_key_last($groups);
        $oldestGroup = $groups[$oldestTimestamp] ?? [];
        unset($groups[$oldestTimestamp]);

        foreach ($groups as $timestamp => $indexes) {
            if (!in_array(count($indexes), self::BOARD_GROUP_SIZES, true)) {
                throw new \InvalidArgumentException(sprintf('Unexpected group of %d records at %s: the export looks truncated or corrupted.', count($indexes), $timestamp));
            }
        }

        if (version_compare($version, self::FIRST_FIXED_VERSION, '>=')) {
            $this->assertConsistentLabels($kinds, $groups);

            return ['kinds' => $kinds, 'warning' => null];
        }

        if (count($oldestGroup) > 11) {
            throw new \InvalidArgumentException(sprintf('Unexpected group of %d records at %s: the export looks truncated or corrupted.', count($oldestGroup), $oldestTimestamp));
        }

        ['labels' => $labels, 'oldestKept' => $oldestKept] = $this->repairOldLabels($rows, array_values(array_reverse($groups, true)), $oldestGroup);
        foreach ($labels as $index => $kind) {
            $kinds[$index] = $kind;
        }
        $this->assertConsistentLabels($kinds, $groups);

        if ($oldestKept) {
            return ['kinds' => $kinds, 'warning' => null];
        }
        if ($groups === []) {
            // Nothing would be left to import
            throw new \InvalidArgumentException(self::REPAIR_FAILED_MESSAGE);
        }

        $skipped = 0;
        foreach ($rows as $index => $row) {
            if ($row['timestamp'] === $oldestTimestamp) {
                $kinds[$index] = null;
                $skipped++;
            }
        }

        return [
            'kinds' => $kinds,
            'warning' => sprintf('The oldest second of this old export (%s) could not be labelled reliably: its %d record(s) were skipped. A longer or newer export will import them.', $oldestTimestamp, $skipped),
        ];
    }

    /**
     * Rebuilds roll / Points Gift labels of exports older than 0.3.1, which mislabel rolls as gifts.
     *
     * The gift comes with the roll completing each tenth cumulative roll: in a 10-roll group it sits at
     * position (C mod 10) counting from the most recent (C = rolls before the group), and a 2-record
     * group is a roll completing a tenth plus its gift. As C at the start of the file is unknown
     * (truncated history), each of the 10 phases is tried and kept only if every gift it places is on
     * a record the file labels "points_gift" with an A character/Arc reward (the old exporter only turns
     * rolls into gifts, never the reverse). All valid phases must agree.
     *
     * The oldest second may be cut (only its most recent records kept), so it is labelled from the phase
     * of the following groups: with P = rolls before the next group (mod 10), its gift is the record at
     * position P when that position was kept. It also rules out the phases it contradicts. When the
     * phases still disagree on it, the oldest second is left out.
     *
     * @param list<array{timestamp: string, position: int, record: array<string, mixed>}> $rows
     * @param list<list<int>> $groupsOldestFirst Complete groups (oldest second excluded), newest row first
     * @param list<int>       $oldestGroup       Rows of the oldest second, newest first
     * @return array{labels: array<int, string>, oldestKept: bool}
     */
    private function repairOldLabels(array $rows, array $groupsOldestFirst, array $oldestGroup): array
    {
        $withOldest = [];
        $withoutOldest = [];
        for ($phase = 0; $phase < 10; $phase++) {
            $labels = $this->labelsForPhase($rows, $groupsOldestFirst, $phase);
            if ($labels === null) {
                continue;
            }
            $withoutOldest[serialize($labels)] = $labels;

            $oldestLabels = $this->labelsForOldestGroup($rows, $oldestGroup, $phase);
            if ($oldestLabels !== null) {
                $merged = $labels + $oldestLabels;
                $withOldest[serialize($merged)] = $merged;
            }
        }

        if (count($withOldest) === 1) {
            return ['labels' => reset($withOldest), 'oldestKept' => true];
        }

        if (count($withoutOldest) === 1) {
            return ['labels' => reset($withoutOldest), 'oldestKept' => false];
        }

        throw new \InvalidArgumentException(self::REPAIR_FAILED_MESSAGE);
    }

    /**
     * @param list<array{timestamp: string, position: int, record: array<string, mixed>}> $rows
     * @param list<int> $indexes Rows of the oldest second, newest first
     * @return array<int, string>|null Labels by row index, or null when the phase contradicts the records
     */
    private function labelsForOldestGroup(array $rows, array $indexes, int $phase): ?array
    {
        $giftPosition = $phase < count($indexes) ? $phase : null;
        $labels = [];
        foreach ($indexes as $position => $index) {
            $isGift = $position === $giftPosition;
            if ($isGift && !self::canBeOldPointsGift($rows[$index]['record'])) {
                return null;
            }
            $labels[$index] = $isGift ? PerfectworldPull::KIND_POINTS_GIFT : PerfectworldPull::KIND_ROLL;
        }

        return $labels;
    }

    /**
     * @param list<array{timestamp: string, position: int, record: array<string, mixed>}> $rows
     * @param list<list<int>> $groupsOldestFirst
     * @return array<int, string>|null Labels by row index, or null when the phase is inconsistent
     */
    private function labelsForPhase(array $rows, array $groupsOldestFirst, int $phase): ?array
    {
        $rolls = $phase;
        $labels = [];

        foreach ($groupsOldestFirst as $indexes) {
            $giftPosition = match (count($indexes)) {
                11 => $rolls % 10,
                2 => ($rolls + 1) % 10 === 0 ? 0 : -1,
                default => null,
            };

            if ($giftPosition === -1) {
                return null;
            }

            foreach ($indexes as $position => $index) {
                $isGift = $position === $giftPosition;
                if ($isGift && !self::canBeOldPointsGift($rows[$index]['record'])) {
                    return null;
                }
                $labels[$index] = $isGift ? PerfectworldPull::KIND_POINTS_GIFT : PerfectworldPull::KIND_ROLL;
            }

            $rolls += $giftPosition === null ? count($indexes) : count($indexes) - 1;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function canBeOldPointsGift(array $record): bool
    {
        $rank = (string) ($record['reward_rank'] ?? '');

        return ($record['result_type'] ?? null) === 'points_gift'
            && in_array($record['reward_type'] ?? null, ['character', 'arc'], true)
            && ($rank === 'A' || $rank === '');
    }

    /**
     * Final labels must follow the game rule whatever the exporter version.
     *
     * @param list<string>              $kinds
     * @param array<string, list<int>>  $groups Row indexes per second, newest row first
     */
    private function assertConsistentLabels(array $kinds, array $groups): void
    {
        foreach ($groups as $indexes) {
            $groupKinds = array_map(static fn (int $index): string => $kinds[$index], $indexes);
            $gifts = count(array_keys($groupKinds, PerfectworldPull::KIND_POINTS_GIFT, true));

            $valid = match (count($groupKinds)) {
                11 => $gifts === 1,
                2 => $groupKinds === [PerfectworldPull::KIND_POINTS_GIFT, PerfectworldPull::KIND_ROLL],
                default => $gifts === 0,
            };

            if (!$valid) {
                throw new \InvalidArgumentException(self::REPAIR_FAILED_MESSAGE);
            }
        }
    }

    /**
     * @param array{timestamp: string, position: int, record: array<string, mixed>} $row
     */
    private function buildRecord(string $bannerKey, string $version, array $row, string $kind): ImportedPullRecord
    {
        $record = $row['record'];
        $rewardId = trim((string) $record['reward_id']);
        $catalogItem = $this->catalog->find($rewardId);

        $fileName = trim((string) ($record['reward_name'] ?? ''));
        $fileRank = strtoupper(trim((string) ($record['reward_rank'] ?? '')));
        $warning = null;

        if ($catalogItem !== null) {
            $name = (string) $catalogItem['name'];
            // Some qualities have no S/A/B rank in the catalog: keep the exporter's rank then
            $rarity = $catalogItem['rarity'] ?? self::RANK_TO_RARITY[$fileRank] ?? null;
            $rewardType = (string) $catalogItem['type'];
        } else {
            $name = $fileName !== '' && strtoupper($fileName) !== 'UNKNOWN' ? $fileName : sprintf('Unknown #%s', $rewardId);
            $rarity = self::RANK_TO_RARITY[$fileRank] ?? null;
            $rewardType = (string) ($record['reward_type'] ?? 'item');
        }

        if ($rarity === null && in_array($rewardType, ['character', 'arc'], true)) {
            $warning = sprintf('Unknown rarity for "%s" (%s): it does not count for pity until the item catalog is updated.', $name, $rewardId);
        } elseif (str_starts_with($name, 'Unknown #')) {
            $warning = sprintf('Unknown item "%s" (saved as "%s").', $rewardId, $name);
        }

        $rollResult = isset($record['roll_result']) && is_numeric($record['roll_result']) ? (int) $record['roll_result'] : null;

        return new ImportedPullRecord(
            bannerKey: $bannerKey,
            poolId: isset($record['pool_id']) && $record['pool_id'] !== '' ? (string) $record['pool_id'] : null,
            pulledAt: $row['timestamp'],
            groupPosition: $row['position'],
            kind: $kind,
            // Relabelled old rolls have no dice value (exported as 0)
            rollResult: $kind === PerfectworldPull::KIND_ROLL && $rollResult !== null && $rollResult >= 1 && $rollResult <= 6 ? $rollResult : null,
            rewardType: $rewardType,
            rewardId: $rewardId,
            name: mb_substr($name, 0, 128),
            rarity: $rarity,
            quantity: max(1, (int) ($record['quantity'] ?? 1)),
            exportUid: isset($record['uid']) ? substr((string) $record['uid'], 0, 32) : null,
            exporterVersion: $version,
            rawPayload: $record,
            warning: $warning,
        );
    }

    private function getBannerName(string $bannerKey): string
    {
        return (string) ($this->game->getGachaConfig()->getBannerGroups('categories')[$bannerKey]['name'] ?? $bannerKey);
    }
}
