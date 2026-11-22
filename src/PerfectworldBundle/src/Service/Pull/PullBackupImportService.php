<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use App\PerfectworldBundle\Model\Pull\ImportedPullRecord;
use App\PerfectworldBundle\Model\Pull\PullBackupImporterInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Upserts the records of one or more export files into a game profile.
 *
 * Identity is (banner, display time, position in the second). A record already stored is rewritten
 * only by a newer exporter version (e.g. a 0.5 export fixing the labels of a 0.3.0 one). Manual and
 * filler rows have negative positions, so a record (always >= 0) never meets them on its identity.
 *
 * Each imported banner is realigned right after its last flush, inside the profile lock: a recorded
 * S replacing a manual one inherits its declared pity, and the fillers follow the new records.
 */
class PullBackupImportService
{
    private const int BATCH_SIZE = 250;
    private const float LOCK_TTL_SECONDS = 300.0;
    private const int MAX_WARNINGS = 50;
    private const int MAX_GAP_WARNINGS = 5;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerfectworldPullRepository $pullRepository,
        private readonly PullBackupImporterManager $importerManager,
        private readonly LockFactory $lockFactory,
        private readonly ManualPullService $manualPullService,
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
    ) {
    }

    public static function getProfileLockKey(int $profileId): string
    {
        return 'perfectworld_profile_pulls_' . $profileId;
    }

    /**
     * Initial per-banner progress of an upload, keyed by banner (shape used by the shared progress banner).
     *
     * @param list<string> $filePaths
     * @return array<string, array<string, mixed>>
     */
    public function buildBannerProgress(PullBackupImporterInterface $importer, array $filePaths): array
    {
        $banners = [];
        foreach ($filePaths as $filePath) {
            foreach ($importer->inspect($filePath)->banners as $bannerKey => $banner) {
                $banners[$bannerKey] = [
                    'gacha_type' => $bannerKey,
                    'name' => $banner['name'],
                    'status' => 'queued',
                    'total' => $banner['count'],
                    'processed' => 0,
                    'imported' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'error' => null,
                ];
            }
        }

        return $banners;
    }

    /**
     * @param list<string>                                  $filePaths
     * @param (callable(PullImportProgress): void)|null     $onProgress
     *
     * @throws PullProfileLockedException when another import is running for the profile
     * @throws \RuntimeException          when every file failed
     */
    public function import(PerfectworldGameProfile $profile, string $importerKey, array $filePaths, ?callable $onProgress = null): PullImportSummary
    {
        $profileId = (int) $profile->getId();
        $importer = $this->importerManager->getByKey($importerKey)
            ?? throw new \InvalidArgumentException(sprintf('Unknown pull backup importer "%s".', $importerKey));

        $lock = $this->lockFactory->createLock(self::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            throw new PullProfileLockedException(sprintf('Another pull operation (import or manual edit) is running for profile #%d.', $profileId));
        }

        try {
            return $this->importFiles($profile, $importer, $filePaths, $lock, $onProgress);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $filePaths
     */
    private function importFiles(
        PerfectworldGameProfile $profile,
        PullBackupImporterInterface $importer,
        array $filePaths,
        SharedLockInterface $lock,
        ?callable $onProgress,
    ): PullImportSummary {
        $profileId = (int) $profile->getId();
        $gameService = $this->getPullTrackerGame($profile);
        $banners = $this->buildBannerProgress($importer, $filePaths);
        $state = ['total' => array_sum(array_column($banners, 'total')), 'processed' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => []];
        $databaseFailure = null;

        $report = function (string $phase) use (&$banners, &$state, $onProgress): void {
            if ($onProgress !== null) {
                $onProgress(new PullImportProgress($phase, $state['total'], $state['processed'], $state['imported'], $state['skipped'], $state['warnings'], $state['updated'], $banners));
            }
        };

        foreach ($filePaths as $filePath) {
            $fileBanners = array_keys($this->buildBannerProgress($importer, [$filePath]));

            if ($databaseFailure !== null) {
                $this->failBanners($banners, $state, $fileBanners, $databaseFailure);
                continue;
            }

            try {
                $records = $importer->parse($this->getProfileReference($profile, $profileId), $filePath);
            } catch (\InvalidArgumentException $e) {
                $this->failBanners($banners, $state, $fileBanners, $e->getMessage());
                $report('importing');
                continue;
            }

            $recordsByBanner = [];
            foreach ($records as $record) {
                $recordsByBanner[$record->bannerKey][] = $record;
                if ($record->warning !== null && count($state['warnings']) < self::MAX_WARNINGS && !in_array($record->warning, $state['warnings'], true)) {
                    $state['warnings'][] = $record->warning;
                }
            }

            foreach ($recordsByBanner as $bannerKey => $bannerRecords) {
                $banners[$bannerKey]['status'] = 'running';
                $report('importing');

                try {
                    $this->importBanner($profile, $profileId, $bannerKey, $bannerRecords, $banners, $state, $lock, $report);
                    $this->realignBanner($profile, $profileId, $gameService, $bannerKey, $state, $lock);
                    $banners[$bannerKey]['status'] = 'completed';
                    $this->addGapWarnings($profile, $profileId, $bannerKey, $state);
                } catch (\UnexpectedValueException $e) {
                    $this->failBanners($banners, $state, [$bannerKey], $e->getMessage());
                } catch (DbalException|ORMException $e) {
                    // The EntityManager is closed after a failed flush: the remaining files cannot be written
                    $databaseFailure = 'Database error during import: ' . $e->getMessage();
                    $this->failBanners($banners, $state, [$bannerKey], $databaseFailure);
                }
                $report('importing');
            }
        }

        $completed = array_filter($banners, static fn (array $banner): bool => $banner['status'] === 'completed');
        if ($completed === []) {
            $report('failed');

            throw new \RuntimeException(implode(' | ', array_filter(array_column($banners, 'error'))) ?: 'All files failed to import.');
        }

        return new PullImportSummary(
            importerKey: $importer::getKey(),
            importerLabel: $importer->getLabel(),
            total: $state['total'],
            imported: $state['imported'],
            skipped: $state['skipped'],
            warnings: $state['warnings'],
            dryRun: false,
            updated: $state['updated'],
            banners: $banners,
        );
    }

    /**
     * @param list<ImportedPullRecord>               $records
     * @param array<string, array<string, mixed>>    $banners
     * @param array<string, mixed>                   $state
     */
    private function importBanner(
        PerfectworldGameProfile $profile,
        int $profileId,
        string $bannerKey,
        array $records,
        array &$banners,
        array &$state,
        SharedLockInterface $lock,
        callable $report,
    ): void {
        $existing = $this->pullRepository->findIdentityMap($this->getProfileReference($profile, $profileId), $bannerKey);

        // Identity conflicts are checked before writing anything, so a banner is never half imported
        foreach ($records as $record) {
            $stored = $existing[$record->getIdentityKey()] ?? null;
            if ($stored !== null && strcasecmp($stored['rewardId'], $record->rewardId) !== 0) {
                throw new \UnexpectedValueException(sprintf(
                    'The record at %s (#%d) is "%s" in the file but "%s" in the stored history: import aborted for this banner.',
                    $record->pulledAt,
                    $record->groupPosition,
                    $record->rewardId,
                    $stored['rewardId'],
                ));
            }
        }

        $pending = 0;
        foreach ($records as $record) {
            $stored = $existing[$record->getIdentityKey()] ?? null;

            if ($stored === null) {
                $pull = (new PerfectworldPull())->setGameProfile($this->getProfileReference($profile, $profileId));
                $this->applyRecord($pull, $record);
                $this->entityManager->persist($pull);
                $existing[$record->getIdentityKey()] = ['id' => 0, 'rewardId' => $record->rewardId, 'exporterVersion' => $record->exporterVersion];
                $this->count($banners, $state, $bannerKey, 'imported');
                $pending++;
            } elseif (version_compare($record->exporterVersion, $stored['exporterVersion'], '>') && $stored['id'] > 0) {
                $pull = $this->entityManager->find(PerfectworldPull::class, $stored['id']);
                if ($pull !== null) {
                    $this->applyRecord($pull, $record);
                    $pending++;
                }
                $this->count($banners, $state, $bannerKey, 'updated');
            } else {
                $this->count($banners, $state, $bannerKey, 'skipped');
            }

            if ($pending >= self::BATCH_SIZE) {
                $this->flushBatch();
                $pending = 0;
                $lock->refresh(self::LOCK_TTL_SECONDS);
                $report('importing');
            }
        }

        $this->flushBatch();
        $lock->refresh(self::LOCK_TTL_SECONDS);
    }

    private function applyRecord(PerfectworldPull $pull, ImportedPullRecord $record): void
    {
        $pull
            ->setBannerKey($record->bannerKey)
            ->setPoolId($record->poolId)
            ->setPulledAt(WallClockDateTimeImmutableType::fromString($record->pulledAt))
            ->setGroupPosition($record->groupPosition)
            ->setKind($record->kind)
            ->setRollResult($record->rollResult)
            ->setRewardType($record->rewardType)
            ->setRewardId($record->rewardId)
            ->setName($record->name)
            ->setRarity($record->rarity)
            ->setQuantity($record->quantity)
            ->setExportUid($record->exportUid)
            ->setExporterVersion($record->exporterVersion)
            ->setRawPayload($record->rawPayload);
    }

    /**
     * Brings the banner to its expected state once its records are stored (the EntityManager has just
     * been cleared, hence the profile reference). Skipped for a game without a pull tracker.
     *
     * @param array<string, mixed> $state
     */
    private function realignBanner(
        PerfectworldGameProfile $profile,
        int $profileId,
        (GameInterface&HasPullTrackerInterface)|null $gameService,
        string $bannerKey,
        array &$state,
        SharedLockInterface $lock,
    ): void {
        if ($gameService === null) {
            return;
        }

        $plan = $this->manualPullService->realign($this->getProfileReference($profile, $profileId), $gameService, $bannerKey);
        foreach ($plan->warnings as $warning) {
            if (count($state['warnings']) < self::MAX_WARNINGS) {
                $state['warnings'][] = $warning;
            }
        }
        $lock->refresh(self::LOCK_TTL_SECONDS);
    }

    /**
     * Board histories have exactly 10 rolls between two consecutive Points Gifts: anything else means
     * missing records (e.g. an export older than the game's ~6-month history window was never taken).
     * Only recorded rows are checked: fillers and manual S rows are rolls the export does not hold.
     *
     * @param array<string, mixed> $state
     */
    private function addGapWarnings(PerfectworldGameProfile $profile, int $profileId, string $bannerKey, array &$state): void
    {
        if (!NevernessToEvernessService::isBoardBanner($bannerKey)) {
            return;
        }

        $rollsSinceGift = null;
        $lastGiftAt = null;
        $gaps = 0;
        foreach ($this->pullRepository->findBannerRows($this->getProfileReference($profile, $profileId), $bannerKey) as $row) {
            if (PerfectworldPullStatsCalculator::getOrigin($row) !== PullOrigin::Recorded) {
                continue;
            }

            $pulledAt = $row['pulledAt']->format('Y-m-d H:i:s');
            if ($row['kind'] === PerfectworldPull::KIND_ROLL && $rollsSinceGift !== null) {
                $rollsSinceGift++;
            } elseif ($row['kind'] === PerfectworldPull::KIND_POINTS_GIFT) {
                if ($rollsSinceGift !== null && $rollsSinceGift !== 10 && $gaps < self::MAX_GAP_WARNINGS) {
                    $state['warnings'][] = sprintf('Possible gap in the %s history between %s and %s (%d rolls between two Points Gifts instead of 10).', $bannerKey, $lastGiftAt, $pulledAt, $rollsSinceGift);
                    $gaps++;
                }
                $rollsSinceGift = 0;
                $lastGiftAt = $pulledAt;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $banners
     * @param array<string, mixed>                $state
     * @param list<string>                        $bannerKeys
     */
    private function failBanners(array &$banners, array &$state, array $bannerKeys, string $error): void
    {
        foreach ($bannerKeys as $bannerKey) {
            $remaining = max(0, (int) ($banners[$bannerKey]['total'] ?? 0) - (int) ($banners[$bannerKey]['processed'] ?? 0));
            $banners[$bannerKey]['status'] = 'failed';
            $banners[$bannerKey]['error'] = $error;
            $state['processed'] += $remaining;
            $state['warnings'][] = sprintf('%s: %s', $banners[$bannerKey]['name'] ?? $bannerKey, $error);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $banners
     * @param array<string, mixed>                $state
     */
    private function count(array &$banners, array &$state, string $bannerKey, string $outcome): void
    {
        $banners[$bannerKey][$outcome]++;
        $banners[$bannerKey]['processed']++;
        $state[$outcome]++;
        $state['processed']++;
    }

    private function flushBatch(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function getPullTrackerGame(PerfectworldGameProfile $profile): (GameInterface&HasPullTrackerInterface)|null
    {
        $gameId = (int) $profile->getGameId();
        $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;

        return $gameService instanceof GameInterface && $gameService instanceof HasPullTrackerInterface ? $gameService : null;
    }

    /**
     * Managed reference to the profile (the EntityManager is cleared between batches).
     */
    private function getProfileReference(PerfectworldGameProfile $profile, int $profileId): PerfectworldGameProfile
    {
        if ($this->entityManager->contains($profile)) {
            return $profile;
        }

        /** @var PerfectworldGameProfile $reference */
        $reference = $this->entityManager->getReference(PerfectworldGameProfile::class, $profileId);

        return $reference;
    }
}
