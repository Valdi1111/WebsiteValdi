<?php

namespace App\HoyoverseBundle\Service\Pull\Importer;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Pull\ImportedPullRecord;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\HoyoverseBundle\Model\Pull\PullBackupValidationResult;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;

class StarRailStationCsvPullImporter implements PullBackupImporterInterface
{
    private const array REQUIRED_COLUMNS = ['uid', 'id', 'rarity', 'time', 'type'];

    public function __construct(
        private readonly NanokaCatalogService $nanokaCatalog,
        private readonly HoyoverseServerTimezoneResolver $timezoneResolver,
    ) {
    }

    public static function getKey(): string
    {
        return 'star_rail_station_csv';
    }

    public function getLabel(): string
    {
        return 'Star Rail Station (CSV)';
    }

    public function getDescription(): string
    {
        return 'Export your warp history from starrailstation.com (Warp Tracker -> Export -> CSV) and upload the resulting .csv file.';
    }

    public function getAcceptedExtensions(): array
    {
        return ['.csv'];
    }

    public static function getSupportedGameServices(): array
    {
        return [HonkaiStarRailService::class];
    }

    public function validate(string $filePath): PullBackupValidationResult
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return PullBackupValidationResult::fail('Backup file is missing or not readable.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return PullBackupValidationResult::fail('Unable to open CSV file.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header) || $header === []) {
                return PullBackupValidationResult::fail('CSV file is empty or has no header row.');
            }

            $columns = $this->normalizeHeaders($header);
            $missing = array_diff(self::REQUIRED_COLUMNS, $columns);
            if ($missing !== []) {
                return PullBackupValidationResult::fail(sprintf(
                    'Invalid Star Rail Station CSV header. Missing column(s): %s.',
                    implode(', ', $missing)
                ));
            }

            $colIndex = array_flip($columns);
            $count = 0;

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                if ($count === 0) {
                    $uid = trim((string) ($row[$colIndex['uid']] ?? ''));
                    $time = trim((string) ($row[$colIndex['time']] ?? ''));
                    if ($uid === '' || $time === '' || $this->parseUtcTimestamp($time) === null) {
                        return PullBackupValidationResult::fail('First CSV data row contains invalid uid or timestamp.');
                    }
                }
                $count++;
            }

            if ($count === 0) {
                return PullBackupValidationResult::fail('No warp records found in this CSV file.');
            }

            return PullBackupValidationResult::ok($count);
        } finally {
            fclose($handle);
        }
    }

    public function parse(HoyoverseGameProfile $profile, string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('Star Rail Station CSV file is missing or not readable.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('Unable to open Star Rail Station CSV file.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header) || $header === []) {
                throw new \InvalidArgumentException('CSV file has no header row.');
            }

            $columns = $this->normalizeHeaders($header);
            $colIndex = array_flip($columns);

            foreach (self::REQUIRED_COLUMNS as $required) {
                if (!isset($colIndex[$required])) {
                    throw new \InvalidArgumentException(sprintf('Missing required CSV column "%s".', $required));
                }
            }

            $records = [];

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                $pullId = trim((string) ($row[$colIndex['uid']] ?? ''));
                $itemId = trim((string) ($row[$colIndex['id']] ?? ''));
                $rarityRaw = trim((string) ($row[$colIndex['rarity']] ?? ''));
                $timeRaw = trim((string) ($row[$colIndex['time']] ?? ''));
                $gachaType = trim((string) ($row[$colIndex['type']] ?? ''));
                $bannerId = isset($colIndex['banner']) ? trim((string) ($row[$colIndex['banner']] ?? '')) : '';

                if ($pullId === '' || $itemId === '' || $timeRaw === '' || $gachaType === '') {
                    continue;
                }

                $utcDt = $this->parseUtcTimestamp($timeRaw);
                if ($utcDt === null) {
                    continue;
                }

                $pulledAt = $this->timezoneResolver->utcToServerWallClock($utcDt, $profile);
                $rarity = is_numeric($rarityRaw) ? (int) $rarityRaw : 3;

                $catalogItem = $this->nanokaCatalog->findHsrItemById($itemId);
                $warning = null;

                if ($catalogItem !== null && $catalogItem['name'] !== '') {
                    $name = $catalogItem['name'];
                    $itemType = $catalogItem['item_type'];
                } else {
                    $itemType = (strlen($itemId) >= 5 && str_starts_with($itemId, '2')) ? 'Light Cone' : 'Character';
                    $name = sprintf('Unknown %s #%s', $itemType, $itemId);
                    $warning = sprintf('Unresolved HSR item_id "%s" (saved as "%s").', $itemId, $name);
                }

                $records[] = new ImportedPullRecord(
                    pullId: $pullId,
                    gachaType: $gachaType,
                    itemType: $itemType,
                    itemId: $itemId,
                    name: $name,
                    rarity: $rarity,
                    pulledAt: $pulledAt,
                    rawPayload: [
                        'id' => $pullId,
                        'item_id' => $itemId,
                        'rank_type' => (string) $rarity,
                        'gacha_type' => $gachaType,
                        'gacha_id' => $bannerId,
                        'time' => $pulledAt->format('Y-m-d H:i:s'),
                        'item_type' => $itemType,
                        'name' => $name,
                        '_import_source' => self::getKey(),
                    ],
                    isSyntheticId: false,
                    warning: $warning,
                );
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null> $header
     * @return list<string>
     */
    private function normalizeHeaders(array $header): array
    {
        $normalized = [];
        foreach ($header as $idx => $col) {
            $value = (string) ($col ?? '');
            if ($idx === 0) {
                // Strip UTF-8 BOM if present
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            }
            $normalized[] = strtolower(trim($value));
        }

        return $normalized;
    }

    private function parseUtcTimestamp(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
