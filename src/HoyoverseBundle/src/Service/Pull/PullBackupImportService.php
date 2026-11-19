<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\PullProfileLockedException;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Model\Pull\PullImportSummary;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Core backup pull import orchestrator. Reusable from CLI commands,
 * Messenger background workers, or tests.
 */
class PullBackupImportService
{
    private const int BATCH_SIZE = 250;
    private const int MAX_WARNINGS = 50;
    private const float LOCK_TTL_SECONDS = 300.0;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyoversePullRepository $pullRepository,
        private readonly PullBackupImporterManager $importerManager,
        private readonly LockFactory $lockFactory,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getProfileLockKey(int $profileId): string
    {
        return sprintf('hoyoverse_pulls_profile_%d', $profileId);
    }

    /**
     * @param (callable(PullImportProgress): void)|null $onProgress
     */
    public function import(
        HoyoverseGameProfile $profile,
        string $importerKey,
        string $filePath,
        ?callable $onProgress = null,
        bool $dryRun = false,
        bool $blockingLock = true,
    ): PullImportSummary {
        $gameId = (int) $profile->getGameId();
        if (!$this->locatorByGameId->has($gameId)) {
            throw new \InvalidArgumentException(sprintf('Unsupported game ID "%d".', $gameId));
        }

        $gameService = $this->locatorByGameId->get($gameId);
        if (!$gameService instanceof HasPullTrackerInterface) {
            throw new \InvalidArgumentException(sprintf('Game "%s" does not support pull tracking.', $gameService->getGameName()));
        }

        $importer = $this->importerManager->getByKey($importerKey);
        if ($importer === null) {
            throw new \InvalidArgumentException(sprintf('Unknown pull backup importer "%s".', $importerKey));
        }

        if (!$this->importerManager->supportsGameService($importer, $gameService)) {
            throw new \InvalidArgumentException(sprintf(
                'Importer "%s" does not support game "%s".',
                $importer->getLabel(),
                $gameService->getGameName()
            ));
        }

        $validation = $importer->validate($filePath);
        if (!$validation->valid) {
            throw new \InvalidArgumentException($validation->error ?? 'Invalid backup file.');
        }

        $profileId = (int) $profile->getId();
        $lock = $this->lockFactory->createLock(self::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire($blockingLock)) {
            throw new PullProfileLockedException(sprintf(
                'Another pull operation is already running for profile #%d.',
                $profileId
            ));
        }

        try {
            $report = static function (string $phase, int $total, int $processed, int $imported, int $skipped, array $warnings) use ($onProgress): void {
                if ($onProgress !== null) {
                    $onProgress(new PullImportProgress($phase, $total, $processed, $imported, $skipped, $warnings));
                }
            };

            $report('parsing', $validation->estimatedCount, 0, 0, 0, []);

            $records = $importer->parse($profile, $filePath);
            $total = count($records);

            if ($total === 0) {
                return new PullImportSummary($importerKey, $importer->getLabel(), 0, 0, 0, [], $dryRun);
            }

            $gachaConfig = $gameService->getGachaConfig();
            $bannerGroups = $gachaConfig->getBannerGroups();

            /** @var array<string, string> $typeToGroupKey */
            $typeToGroupKey = [];
            foreach ($bannerGroups as $groupKey => $group) {
                foreach ($group['gacha_types'] as $gt) {
                    $typeToGroupKey[(string) $gt] = (string) $groupKey;
                }
            }

            $existingPullIds = $this->pullRepository->findExistingPullIdSet($profile);

            $hasSyntheticRecords = false;
            foreach ($records as $record) {
                if ($record->isSyntheticId) {
                    $hasSyntheticRecords = true;
                    break;
                }
            }

            /** @var array<string, int> $existingLogicalCounts */
            $existingLogicalCounts = $hasSyntheticRecords
                ? $this->pullRepository->findExistingLogicalPullCounts($profile, $typeToGroupKey)
                : [];

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $pendingInBatch = 0;
            /** @var array<string, true> $warningSet */
            $warningSet = [];

            $report('importing', $total, 0, 0, 0, []);

            /** @var HoyoverseGameProfile $profileRef */
            $profileRef = $this->entityManager->getReference(HoyoverseGameProfile::class, $profileId);

            foreach ($records as $record) {
                $processed++;

                if ($record->warning !== null && count($warningSet) < self::MAX_WARNINGS) {
                    $warningSet[$record->warning] = true;
                }

                // 1. Exact pull_id deduplication (handles HSR, ZZZ, and repeated paimon.moe imports)
                if (isset($existingPullIds[$record->pullId])) {
                    $skipped++;
                } else {
                    // 2. Logical multiset deduplication for synthetic IDs (e.g. paimon.moe overlapping live API pulls)
                    $skippedLogical = false;
                    if ($record->isSyntheticId) {
                        $groupKey = $typeToGroupKey[$record->gachaType] ?? $record->gachaType;
                        $logicalKey = HoyoversePullRepository::buildLogicalKey($groupKey, $record->pulledAt, $record->name);
                        if (($existingLogicalCounts[$logicalKey] ?? 0) > 0) {
                            $existingLogicalCounts[$logicalKey]--;
                            $skipped++;
                            $skippedLogical = true;
                        }
                    }

                    if (!$skippedLogical) {
                        $existingPullIds[$record->pullId] = true;
                        $imported++;

                        if (!$dryRun) {
                            $bannerName = $gachaConfig->getBanner($record->gachaType)?->getName();

                            $pull = (new HoyoversePull())
                                ->setGameProfile($profileRef)
                                ->setPullId($record->pullId)
                                ->setGachaType($record->gachaType)
                                ->setGachaName($bannerName)
                                ->setItemType($record->itemType)
                                ->setItemId($record->itemId)
                                ->setName($record->name)
                                ->setRarity($record->rarity)
                                ->setPulledAt($record->pulledAt)
                                ->setRawPayload($record->rawPayload);

                            $this->entityManager->persist($pull);
                            $pendingInBatch++;
                        }
                    }
                }

                if ($processed % self::BATCH_SIZE === 0) {
                    if (!$dryRun && $pendingInBatch > 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                        /** @var HoyoverseGameProfile $profileRef */
                        $profileRef = $this->entityManager->getReference(HoyoverseGameProfile::class, $profileId);
                        $pendingInBatch = 0;
                    }
                    $lock->refresh();
                    $report('importing', $total, $processed, $imported, $skipped, array_keys($warningSet));
                }
            }

            if (!$dryRun && $pendingInBatch > 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }

            $warnings = array_keys($warningSet);
            $this->logger?->info(sprintf(
                'Pull backup import (%s) finished for profile #%d: total=%d, imported=%d, skipped=%d, dryRun=%s.',
                $importerKey,
                $profileId,
                $total,
                $imported,
                $skipped,
                $dryRun ? 'true' : 'false'
            ));

            return new PullImportSummary(
                importerKey: $importerKey,
                importerLabel: $importer->getLabel(),
                total: $total,
                imported: $imported,
                skipped: $skipped,
                warnings: $warnings,
                dryRun: $dryRun,
            );
        } finally {
            $lock->release();
        }
    }
}
