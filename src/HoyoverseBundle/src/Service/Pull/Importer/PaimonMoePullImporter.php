<?php

namespace App\HoyoverseBundle\Service\Pull\Importer;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Pull\ImportedPullRecord;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\HoyoverseBundle\Model\Pull\SyntheticPullReconcilerInterface;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;

class PaimonMoePullImporter implements PullBackupImporterInterface, SyntheticPullReconcilerInterface
{
    public const string SYNTHETIC_PREFIX = 'paimon_';

    /**
     * Maps paimon.moe top-level wish counter keys to default HoYoverse gacha_type codes
     * (used when older pull records omit the per-pull "code" field).
     *
     * @var array<string, string>
     */
    private const array BANNER_KEY_DEFAULTS = [
        'wish-counter-character-event' => '301',
        'wish-counter-weapon-event' => '302',
        'wish-counter-standard' => '200',
        'wish-counter-beginners' => '100',
        'wish-counter-chronicled' => '500',
    ];

    public function __construct(
        private readonly NanokaCatalogService $nanokaCatalog,
        private readonly HoyoverseServerTimezoneResolver $timezoneResolver,
        private readonly HoyoversePullRepository $pullRepository,
    ) {
    }

    public static function getKey(): string
    {
        return 'paimon_moe';
    }

    public function getLabel(): string
    {
        return 'Paimon.moe (JSON)';
    }

    public function getDescription(): string
    {
        return 'Export your wish history from paimon.moe (Settings -> Export Data -> JSON) and upload the resulting .json file.';
    }

    public function getAcceptedExtensions(): array
    {
        return ['.json'];
    }

    public static function getSupportedGameServices(): array
    {
        return [GenshinImpactService::class];
    }

    public function validate(string $filePath): PullBackupValidationResult
    {
        $decoded = $this->decodeJsonFile($filePath, $error);
        if ($decoded === null) {
            return PullBackupValidationResult::fail($error ?? 'Invalid JSON file.');
        }

        $foundBucket = false;
        $estimatedCount = 0;

        foreach (array_keys(self::BANNER_KEY_DEFAULTS) as $bannerKey) {
            if (!isset($decoded[$bannerKey])) {
                continue;
            }
            if (!is_array($decoded[$bannerKey]) || !isset($decoded[$bannerKey]['pulls']) || !is_array($decoded[$bannerKey]['pulls'])) {
                return PullBackupValidationResult::fail(sprintf('Malformed Paimon.moe bucket "%s": missing "pulls" array.', $bannerKey));
            }
            $foundBucket = true;
            $pulls = $decoded[$bannerKey]['pulls'];
            $estimatedCount += count($pulls);

            if ($pulls !== []) {
                $sample = $pulls[0];
                if (!is_array($sample) || !isset($sample['id'], $sample['time'], $sample['type'])) {
                    return PullBackupValidationResult::fail(sprintf('Invalid pull entry format inside "%s".', $bannerKey));
                }
            }
        }

        if (!$foundBucket) {
            return PullBackupValidationResult::fail('File does not look like a Paimon.moe backup (no wish-counter-* sections found).');
        }

        if ($estimatedCount === 0) {
            return PullBackupValidationResult::fail('No wish records found in this Paimon.moe backup.');
        }

        return PullBackupValidationResult::ok($estimatedCount);
    }

    public function parse(HoyoverseGameProfile $profile, string $filePath): array
    {
        $decoded = $this->decodeJsonFile($filePath, $error);
        if ($decoded === null) {
            throw new \InvalidArgumentException($error ?? 'Invalid Paimon.moe JSON file.');
        }

        $records = [];
        /** @var array<string, int> $secondCounters */
        $secondCounters = [];

        foreach (self::BANNER_KEY_DEFAULTS as $bannerKey => $defaultGachaType) {
            $pulls = $decoded[$bannerKey]['pulls'] ?? null;
            if (!is_array($pulls)) {
                continue;
            }

            foreach ($pulls as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $slug = isset($entry['id']) ? trim((string) $entry['id']) : '';
                $timeRaw = isset($entry['time']) ? trim((string) $entry['time']) : '';
                $rawType = isset($entry['type']) ? strtolower(trim((string) $entry['type'])) : '';

                if ($slug === '' || $timeRaw === '') {
                    continue;
                }

                $pulledAt = $this->timezoneResolver->parseServerWallClock($timeRaw);
                if ($pulledAt === null) {
                    continue;
                }

                $gachaType = isset($entry['code']) && trim((string) $entry['code']) !== ''
                    ? trim((string) $entry['code'])
                    : $defaultGachaType;

                $normalizedType = $rawType === 'character' ? 'character' : 'weapon';
                $itemType = $normalizedType === 'character' ? 'Character' : 'Weapon';

                $catalogItem = $this->nanokaCatalog->findGenshinItem(null, $slug, $normalizedType);
                $warning = null;

                if ($catalogItem !== null && $catalogItem['name'] !== '') {
                    $name = $catalogItem['name'];
                    $rarity = $catalogItem['rarity'];
                } else {
                    $name = $this->humanizeSlug($slug);
                    $rarity = $normalizedType === 'character' ? 4 : 3;
                    $warning = sprintf('Unresolved Genshin %s slug "%s" (fallback name "%s", rarity %d).', $normalizedType, $slug, $name, $rarity);
                }

                $counterKey = $gachaType . '|' . $pulledAt->format('YmdHis');
                $seq = $secondCounters[$counterKey] ?? 0;
                $secondCounters[$counterKey] = $seq + 1;

                $syntheticPullId = sprintf('%s%s_%s_%03d', self::SYNTHETIC_PREFIX, $gachaType, $pulledAt->format('YmdHis'), $seq);

                $records[] = new ImportedPullRecord(
                    pullId: $syntheticPullId,
                    gachaType: $gachaType,
                    itemType: $itemType,
                    itemId: '',
                    name: $name,
                    rarity: $rarity,
                    pulledAt: $pulledAt,
                    rawPayload: array_merge($entry, [
                        '_import_source' => self::getKey(),
                        '_banner_key' => $bannerKey,
                    ]),
                    isSyntheticId: true,
                    warning: $warning,
                );
            }
        }

        return $records;
    }

    public function supportsGameService(string|GameInterface $gameService): bool
    {
        $class = is_object($gameService) ? $gameService::class : $gameService;

        return is_a($class, GenshinImpactService::class, true);
    }

    public function reconcile(
        HoyoverseGameProfile $profile,
        HoyoversePull $incomingRealPull,
        GameInterface $gameService,
    ): ?HoyoversePull {
        $pulledAt = $incomingRealPull->getPulledAt();
        $incomingName = (string) $incomingRealPull->getName();
        $incomingPullId = (string) $incomingRealPull->getPullId();
        if ($pulledAt === null || $incomingName === '' || $incomingPullId === '') {
            return null;
        }

        $groupGachaTypes = $this->resolveBannerGroupTypes($gameService, (string) $incomingRealPull->getGachaType());

        $candidates = $this->pullRepository->findSyntheticCandidatesForReconciliation(
            $profile,
            self::SYNTHETIC_PREFIX,
            $groupGachaTypes,
            $pulledAt,
        );

        $targetLooseName = NanokaCatalogService::normalizeLoose($incomingName);

        foreach ($candidates as $candidate) {
            // Guard against re-matching an entity already promoted earlier in the same unflushed batch
            if (!str_starts_with((string) $candidate->getPullId(), self::SYNTHETIC_PREFIX)) {
                continue;
            }

            if (NanokaCatalogService::normalizeLoose((string) $candidate->getName()) !== $targetLooseName) {
                continue;
            }

            $candidate->setPullId($incomingPullId);
            if ($incomingRealPull->getGachaType() !== null) {
                $candidate->setGachaType($incomingRealPull->getGachaType());
            }
            if ($incomingRealPull->getGachaName() !== null) {
                $candidate->setGachaName($incomingRealPull->getGachaName());
            }
            if ($incomingRealPull->getItemType() !== null) {
                $candidate->setItemType($incomingRealPull->getItemType());
            }
            $candidate->setItemId($incomingRealPull->getItemId());
            $candidate->setName($incomingName);
            if ($incomingRealPull->getRarity() !== null) {
                $candidate->setRarity($incomingRealPull->getRarity());
            }
            $candidate->setRawPayload($incomingRealPull->getRawPayload());

            return $candidate;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveBannerGroupTypes(GameInterface $gameService, string $gachaType): array
    {
        if ($gameService instanceof HasPullTrackerInterface) {
            foreach ($gameService->getGachaConfig()->getBannerGroups() as $group) {
                if (in_array($gachaType, $group['gacha_types'], true)) {
                    return array_values(array_map('strval', $group['gacha_types']));
                }
            }
        }

        return $gachaType !== '' ? [$gachaType] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonFile(string $filePath, ?string &$error = null): ?array
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

        return $decoded;
    }

    private function humanizeSlug(string $slug): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }
}
