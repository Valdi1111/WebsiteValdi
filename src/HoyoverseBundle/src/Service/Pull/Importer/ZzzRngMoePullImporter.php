<?php

namespace App\HoyoverseBundle\Service\Pull\Importer;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Pull\ImportedPullRecord;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\HoyoverseBundle\Model\Pull\PullBackupValidationResult;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;

class ZzzRngMoePullImporter implements PullBackupImporterInterface
{
    /**
     * Maps zzz.rng.moe gachaType codes to HoYoverse API gacha_type values.
     *
     * @var array<string, string>
     */
    private const array GACHA_TYPE_MAP = [
        '1001' => '1',  // Standard Channel
        '2001' => '2',  // Exclusive Channel
        '3001' => '3',  // W-Engine Channel
        '5001' => '5',  // Bangboo Channel
        '12001' => '12', // Exclusive Rescreening
        '13001' => '13', // W-Engine Reverberation
    ];

    public function __construct(
        private readonly NanokaCatalogService $nanokaCatalog,
        private readonly HoyoverseServerTimezoneResolver $timezoneResolver,
    ) {
    }

    public static function getKey(): string
    {
        return 'zzz_rng_moe';
    }

    public function getLabel(): string
    {
        return 'ZZZ.rng.moe (JSON)';
    }

    public function getDescription(): string
    {
        return 'Export your Signal Search backup from zzz.rng.moe (Settings -> Backup -> Export JSON) and upload the resulting .json file.';
    }

    public function getAcceptedExtensions(): array
    {
        return ['.json'];
    }

    public static function getSupportedGameServices(): array
    {
        return [ZenlessZoneZeroService::class];
    }

    public function validate(string $filePath): PullBackupValidationResult
    {
        $itemsByBanner = $this->extractItemsByBanner($filePath, $error);
        if ($itemsByBanner === null) {
            return PullBackupValidationResult::fail($error ?? 'Invalid ZZZ.rng.moe backup file.');
        }

        $estimatedCount = 0;
        $validatedSample = false;

        foreach ($itemsByBanner as $bannerKey => $pulls) {
            if (!is_array($pulls)) {
                return PullBackupValidationResult::fail(sprintf('Malformed banner "%s" in ZZZ.rng.moe backup.', (string) $bannerKey));
            }
            $estimatedCount += count($pulls);

            if (!$validatedSample && $pulls !== []) {
                $sample = $pulls[0];
                if (!is_array($sample) || !isset($sample['uid'], $sample['id'], $sample['timestamp'], $sample['rarity'])) {
                    return PullBackupValidationResult::fail('Invalid signal entry format in ZZZ.rng.moe backup.');
                }
                $validatedSample = true;
            }
        }

        if ($estimatedCount === 0) {
            return PullBackupValidationResult::fail('No signal records found in this ZZZ.rng.moe backup.');
        }

        return PullBackupValidationResult::ok($estimatedCount);
    }

    public function parse(HoyoverseGameProfile $profile, string $filePath): array
    {
        $itemsByBanner = $this->extractItemsByBanner($filePath, $error);
        if ($itemsByBanner === null) {
            throw new \InvalidArgumentException($error ?? 'Invalid ZZZ.rng.moe backup file.');
        }

        $records = [];

        foreach ($itemsByBanner as $bannerKey => $pulls) {
            if (!is_array($pulls)) {
                continue;
            }

            foreach ($pulls as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $pullId = isset($entry['uid']) ? trim((string) $entry['uid']) : '';
                $itemId = isset($entry['id']) ? trim((string) $entry['id']) : '';
                $timestampMs = $entry['timestamp'] ?? null;
                $rawGachaType = isset($entry['gachaType']) ? (string) $entry['gachaType'] : (string) $bannerKey;

                if ($pullId === '' || $itemId === '' || !is_numeric($timestampMs)) {
                    continue;
                }

                $seconds = (int) floor(((float) $timestampMs) / 1000);
                $utcDt = (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
                $pulledAt = $this->timezoneResolver->utcToServerWallClock($utcDt, $profile);

                $gachaType = $this->mapGachaType($rawGachaType);
                $rarity = isset($entry['rarity']) && is_numeric($entry['rarity']) ? (int) $entry['rarity'] : 2;

                $catalogItem = $this->nanokaCatalog->findZzzItem($itemId, null, null);
                $warning = null;

                if ($catalogItem !== null && $catalogItem['name'] !== '') {
                    $name = $catalogItem['name'];
                    $itemType = $catalogItem['item_type'];
                } else {
                    $itemType = $this->guessItemTypeFromId($itemId);
                    $name = sprintf('Unknown %s #%s', $itemType, $itemId);
                    $warning = sprintf('Unresolved ZZZ item_id "%s" (saved as "%s").', $itemId, $name);
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
                        'gacha_id' => isset($entry['gacha']) ? (string) $entry['gacha'] : '0',
                        'time' => $pulledAt->format('Y-m-d H:i:s'),
                        'item_type' => $itemType,
                        'name' => $name,
                        '_import_source' => self::getKey(),
                    ],
                    isSyntheticId: false,
                    warning: $warning,
                );
            }
        }

        return $records;
    }

    /**
     * @return array<string|int, mixed>|null
     */
    private function extractItemsByBanner(string $filePath, ?string &$error = null): ?array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            $error = 'Backup file is missing or not readable.';

            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            $error = 'Backup file is empty.';

            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = sprintf('Invalid JSON syntax: %s', $e->getMessage());

            return null;
        }

        if (!is_array($decoded)) {
            $error = 'JSON root must be an object.';

            return null;
        }

        if (($decoded['game'] ?? null) !== 'zzz') {
            $error = 'File does not appear to be a ZZZ.rng.moe backup (expected "game": "zzz").';

            return null;
        }

        $profiles = $decoded['data']['profiles'] ?? null;
        if (!is_array($profiles) || $profiles === []) {
            $error = 'No profiles found inside ZZZ.rng.moe backup.';

            return null;
        }

        $curProfileId = $decoded['data']['curProfileId'] ?? null;
        $profileData = ($curProfileId !== null && isset($profiles[(string) $curProfileId]))
            ? $profiles[(string) $curProfileId]
            : reset($profiles);

        if (!is_array($profileData)) {
            $error = 'Invalid profile structure inside ZZZ.rng.moe backup.';

            return null;
        }

        $items = $profileData['stores']['0']['items'] ?? null;
        if (!is_array($items)) {
            $error = 'Missing signal items store (stores.0.items) in ZZZ.rng.moe backup.';

            return null;
        }

        return $items;
    }

    private function mapGachaType(string $rawGachaType): string
    {
        $key = trim($rawGachaType);
        if (isset(self::GACHA_TYPE_MAP[$key])) {
            return self::GACHA_TYPE_MAP[$key];
        }

        if (preg_match('/^(\d+)001$/', $key, $matches)) {
            return $matches[1];
        }

        return $key;
    }

    private function guessItemTypeFromId(string $itemId): string
    {
        if (str_starts_with($itemId, '5') && strlen($itemId) >= 5) {
            return 'Bangboo';
        }
        if (strlen($itemId) >= 5) {
            return 'W-Engines';
        }

        return 'Agents';
    }
}
