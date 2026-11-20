<?php

namespace App\GryphlineBundle\Service\Pull;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class PullBackupImportService
{
    private const int BATCH_SIZE = 250;
    private const float LOCK_TTL_SECONDS = 300.0;
    private const int MAX_WARNINGS = 50;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GryphlinePullRepository $pullRepository,
        private readonly PullBackupImporterManager $importerManager,
        private readonly LockFactory $lockFactory,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameBiz,
    ) {
    }

    public static function getProfileLockKey(int $profileId): string
    {
        return 'gryphline_profile_pulls_' . $profileId;
    }

    /**
     * Validates, parses, deduplicates, and persists pulls from a backup file for the target profile.
     *
     * @param (callable(PullImportProgress): void)|null $onProgress
     */
    public function import(
        GryphlineGameProfile $profile,
        string $importerKey,
        string $filePath,
        ?callable $onProgress = null,
        bool $dryRun = false,
        bool $blockingLock = false,
    ): PullImportSummary {
        $profileId = (int) $profile->getId();
        if ($profileId <= 0) {
            throw new \InvalidArgumentException('Target GryphlineGameProfile must have a valid persisted ID.');
        }

        $gameBiz = (string) $profile->getGameBiz();
        if (!$this->locatorByGameBiz->has($gameBiz)) {
            throw new \InvalidArgumentException(sprintf('Unsupported game service for game_biz "%s".', $gameBiz));
        }

        $gameService = $this->locatorByGameBiz->get($gameBiz);
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new \InvalidArgumentException(sprintf('Game "%s" does not support pull tracking.', $gameService->getGameName()));
        }

        $importer = $this->importerManager->getByKey($importerKey);
        if ($importer === null) {
            throw new \InvalidArgumentException(sprintf('Unknown pull backup importer "%s".', $importerKey));
        }

        if (!$this->importerManager->supportsGameService($importer, $gameService)) {
            throw new \InvalidArgumentException(sprintf(
                'Importer "%s" does not support game "%s".',
                $importerKey,
                $gameService->getGameName()
            ));
        }

        $lock = $this->lockFactory->createLock(self::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire($blockingLock)) {
            throw new PullProfileLockedException(sprintf(
                'Another pull sync or import is currently running for profile #%d.',
                $profileId
            ));
        }

        try {
            $validation = $importer->validate($filePath);
            if (!$validation->valid) {
                throw new \InvalidArgumentException($validation->error ?? 'Invalid backup file.');
            }

            if ($onProgress !== null) {
                $onProgress(new PullImportProgress('parsing', $validation->estimatedCount, 0, 0, 0));
            }

            $records = $importer->parse($profile, $filePath);
            $total = count($records);

            $gachaConfig = $gameService->getGachaConfig();
            $bannerGroups = $gachaConfig->getBannerGroups('categories');
            $poolTypeToGroupKey = [];
            foreach ($bannerGroups as $groupKey => $group) {
                foreach ($group['categories'] as $cat) {
                    $poolTypeToGroupKey[(string) $cat] = (string) $groupKey;
                }
            }

            $existingSeqKeys = $this->pullRepository->findExistingSeqKeySet($profile);
            $hasSyntheticRecords = false;
            foreach ($records as $record) {
                if ($record->isSyntheticId) {
                    $hasSyntheticRecords = true;
                    break;
                }
            }

            $existingLogicalCounts = $hasSyntheticRecords
                ? $this->pullRepository->findExistingLogicalPullCounts($profile, $poolTypeToGroupKey)
                : [];

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $pendingInBatch = 0;
            $warnings = [];
            $profileRef = $profile;

            if ($onProgress !== null) {
                $onProgress(new PullImportProgress('importing', $total, 0, 0, 0));
            }

            foreach ($records as $record) {
                $processed++;

                if ($record->warning !== null && count($warnings) < self::MAX_WARNINGS) {
                    $warnings[] = $record->warning;
                }

                $seqKey = GryphlinePullRepository::buildSeqKey($record->poolCategory, $record->seqId);
                if (isset($existingSeqKeys[$seqKey])) {
                    $skipped++;
                } elseif ($record->isSyntheticId) {
                    $groupKey = $poolTypeToGroupKey[$record->poolType] ?? $record->poolType;
                    $logicalKey = GryphlinePullRepository::buildLogicalKey($groupKey, $record->pulledAt, $record->name);
                    if (($existingLogicalCounts[$logicalKey] ?? 0) > 0) {
                        $existingLogicalCounts[$logicalKey]--;
                        $skipped++;
                    } else {
                        $existingSeqKeys[$seqKey] = true;
                        $imported++;
                        if (!$dryRun) {
                            $this->persistRecord($profileRef, $record);
                            $pendingInBatch++;
                        }
                    }
                } else {
                    $existingSeqKeys[$seqKey] = true;
                    $imported++;
                    if (!$dryRun) {
                        $this->persistRecord($profileRef, $record);
                        $pendingInBatch++;
                    }
                }

                if (!$dryRun && $pendingInBatch >= self::BATCH_SIZE) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    /** @var GryphlineGameProfile $profileRef */
                    $profileRef = $this->entityManager->getReference(GryphlineGameProfile::class, $profileId);
                    $pendingInBatch = 0;
                    $lock->refresh(self::LOCK_TTL_SECONDS);
                }

                if ($onProgress !== null && ($processed % self::BATCH_SIZE === 0 || $processed === $total)) {
                    $onProgress(new PullImportProgress('importing', $total, $processed, $imported, $skipped, $warnings));
                }
            }

            if (!$dryRun && $pendingInBatch > 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }

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

    private function persistRecord(
        GryphlineGameProfile $profileRef,
        \App\GryphlineBundle\Model\Pull\ImportedPullRecord $record,
    ): void {
        $pull = (new GryphlinePull())
            ->setGameProfile($profileRef)
            ->setSeqId($record->seqId)
            ->setPoolType($record->poolType)
            ->setPoolCategory($record->poolCategory)
            ->setPoolId($record->poolId)
            ->setPoolName($record->poolName)
            ->setItemId($record->itemId)
            ->setName($record->name)
            ->setRarity($record->rarity)
            ->setPulledAt($record->pulledAt)
            ->setGachaTs($record->gachaTs)
            ->setRawPayload($record->rawPayload);

        $this->entityManager->persist($pull);
    }
}
