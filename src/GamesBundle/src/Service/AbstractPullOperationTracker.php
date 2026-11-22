<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\PullOperationKind;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks live progress of all pull operations (backup imports, PowerShell imports,
 * manual syncs, and scheduled syncs) in cache and broadcasts updates via Mercure.
 */
abstract class AbstractPullOperationTracker
{
    private const int TTL_SECONDS = 3600;
    private const string KEY_OPERATION_PREFIX = 'pull_op_';
    private const string KEY_PROFILE_LATEST_PREFIX = 'pull_op_profile_';

    private const string FALLBACK_DOMAIN = 'games.local';

    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
        private readonly HubInterface $mercureHub,
        private readonly ?string $gamesDomain = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Provider segment (e.g. "hoyoverse") used to namespace Mercure topics: profile IDs come from
     * per-provider tables, so topics on the shared games host must not collide across providers.
     */
    abstract protected function getProviderKey(): string;

    public function getProfileTopic(int $profileId): string
    {
        $domain = trim((string) $this->gamesDomain);
        if ($domain === '') {
            $domain = self::FALLBACK_DOMAIN;
        }

        return sprintf('https://%s/%s/profiles/%d/pulls/progress', $domain, $this->getProviderKey(), $profileId);
    }

    /**
     * @param array<string, array<string, mixed>>|null $banners Optional per-banner progress (multi-file imports),
     *                                                          keyed by banner with at least "gacha_type" and "name"
     * @return array<string, mixed>
     */
    public function startBackupImport(
        int $profileId,
        ?int $userId,
        string $importerKey,
        string $importerLabel,
        int $estimatedTotal,
        ?array $banners = null,
    ): array {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $operation = [
            'id' => Uuid::v4()->toRfc4122(),
            'profile_id' => $profileId,
            'user_id' => $userId,
            'kind' => PullOperationKind::BACKUP_IMPORT->value,
            'label' => $importerLabel,
            'importer_key' => $importerKey,
            'status' => 'queued',
            'phase' => 'queued',
            'percent' => 0,
            'total' => $estimatedTotal,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'updated' => 0,
            'promoted' => 0,
            'warnings' => [],
            'error' => null,
            'banners' => $banners,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ];

        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateBackupImportProgress(string $operationId, PullImportProgress $progress): ?array
    {
        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        $total = max(0, $progress->total);
        $processed = max(0, $progress->processed);
        $percent = $total > 0 ? min(99, (int) floor(($processed / $total) * 100)) : 0;

        $operation['status'] = 'running';
        $operation['phase'] = $progress->phase;
        $operation['total'] = $total;
        $operation['processed'] = $processed;
        $operation['imported'] = $progress->imported;
        $operation['skipped'] = $progress->skipped;
        $operation['percent'] = $percent;
        $operation['warnings'] = $progress->warnings;
        if ($progress->updated !== null) {
            $operation['updated'] = $progress->updated;
        }
        if ($progress->banners !== null) {
            $operation['banners'] = $progress->banners;
        }
        $operation['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function completeBackupImport(string $operationId, PullImportSummary $summary): ?array
    {
        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $operation['status'] = 'completed';
        $operation['phase'] = 'completed';
        $operation['percent'] = 100;
        $operation['total'] = $summary->total;
        $operation['processed'] = $summary->total;
        $operation['imported'] = $summary->imported;
        $operation['skipped'] = $summary->skipped;
        $operation['updated'] = $summary->updated;
        $operation['warnings'] = $summary->warnings;
        if ($summary->banners !== null) {
            $operation['banners'] = $summary->banners;
        }
        $operation['updated_at'] = $now;
        $operation['finished_at'] = $now;

        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @param array<string, string> $bannerNamesByType Map of bannerKey/gachaType/category => bannerName
     * @return array<string, mixed>
     */
    public function startSyncOperation(
        int $profileId,
        ?int $userId,
        PullOperationKind $kind,
        array $bannerNamesByType,
    ): array {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $banners = [];
        foreach ($bannerNamesByType as $bannerKey => $bannerName) {
            $key = (string) $bannerKey;
            $banners[$key] = [
                'gacha_type' => $key,
                'name' => $bannerName,
                'status' => 'queued',
                'pages' => 0,
                'imported' => 0,
                'promoted' => 0,
                'error' => null,
            ];
        }

        $operation = [
            'id' => Uuid::v4()->toRfc4122(),
            'profile_id' => $profileId,
            'user_id' => $userId,
            'kind' => $kind->value,
            'label' => $kind->getLabel(),
            'importer_key' => null,
            'status' => 'queued',
            'phase' => 'queued',
            'percent' => 0,
            'total' => count($banners),
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'promoted' => 0,
            'warnings' => [],
            'error' => null,
            'banners' => $banners,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ];

        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordSyncBannerPage(
        string $operationId,
        string $bannerKey,
        int $newCount,
        int $promotedCount,
        bool $bannerFinished,
        ?string $bannerName = null,
    ): ?array {
        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        $banners = is_array($operation['banners'] ?? null) ? $operation['banners'] : [];
        if (!isset($banners[$bannerKey])) {
            $banners[$bannerKey] = [
                'gacha_type' => $bannerKey,
                'name' => $bannerName ?? ('Banner ' . $bannerKey),
                'status' => 'running',
                'pages' => 0,
                'imported' => 0,
                'promoted' => 0,
                'error' => null,
            ];
        }

        $banners[$bannerKey]['pages'] = (int) ($banners[$bannerKey]['pages'] ?? 0) + 1;
        $banners[$bannerKey]['imported'] = (int) ($banners[$bannerKey]['imported'] ?? 0) + $newCount;
        $banners[$bannerKey]['promoted'] = (int) ($banners[$bannerKey]['promoted'] ?? 0) + $promotedCount;
        $banners[$bannerKey]['status'] = $bannerFinished ? 'completed' : 'running';
        // A page was read successfully: a failure recorded by an earlier attempt is stale
        $banners[$bannerKey]['error'] = null;

        $operation['banners'] = $banners;
        $operation = $this->recomputeSyncAggregates($operation);
        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function failSyncBanner(string $operationId, string $bannerKey, string $error, ?string $bannerName = null): ?array
    {
        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        $banners = is_array($operation['banners'] ?? null) ? $operation['banners'] : [];
        if (!isset($banners[$bannerKey])) {
            $banners[$bannerKey] = [
                'gacha_type' => $bannerKey,
                'name' => $bannerName ?? ('Banner ' . $bannerKey),
                'status' => 'failed',
                'pages' => 0,
                'imported' => 0,
                'promoted' => 0,
                'error' => $error,
            ];
        } else {
            $banners[$bannerKey]['status'] = 'failed';
            $banners[$bannerKey]['error'] = $error;
        }

        $operation['banners'] = $banners;
        $operation = $this->recomputeSyncAggregates($operation);
        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function failOperation(string $operationId, string $error): ?array
    {
        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $operation['status'] = 'failed';
        $operation['phase'] = 'failed';
        $operation['error'] = $error;
        // Banners still waiting or running will never finish
        if (is_array($operation['banners'] ?? null)) {
            foreach ($operation['banners'] as $key => $banner) {
                if (in_array($banner['status'] ?? 'queued', ['queued', 'running'], true)) {
                    $operation['banners'][$key]['status'] = 'failed';
                    $operation['banners'][$key]['error'] ??= $error;
                }
            }
        }
        $operation['updated_at'] = $now;
        $operation['finished_at'] = $now;

        $this->saveAndPublish($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOperation(string $operationId): ?array
    {
        $item = $this->cachePool->getItem(self::KEY_OPERATION_PREFIX . $operationId);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    /**
     * Returns the latest operation for the given profile if it is currently queued or running.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveOperationForProfile(int $profileId): ?array
    {
        $pointer = $this->cachePool->getItem(self::KEY_PROFILE_LATEST_PREFIX . $profileId);
        if (!$pointer->isHit()) {
            return null;
        }

        $operationId = $pointer->get();
        if (!is_string($operationId) || $operationId === '') {
            return null;
        }

        $operation = $this->getOperation($operationId);
        if ($operation === null) {
            return null;
        }

        if (!in_array($operation['status'] ?? '', ['queued', 'running'], true)) {
            return null;
        }

        return $operation;
    }

    public function hasActiveBackupImport(int $profileId): bool
    {
        $active = $this->getActiveOperationForProfile($profileId);

        return $active !== null && ($active['kind'] ?? null) === PullOperationKind::BACKUP_IMPORT->value;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function recomputeSyncAggregates(array $operation): array
    {
        $banners = is_array($operation['banners'] ?? null) ? $operation['banners'] : [];
        $totalBanners = count($banners);
        $finishedBanners = 0;
        $failedBanners = 0;
        $runningBanners = 0;
        $totalImported = 0;
        $totalPromoted = 0;
        $errors = [];

        foreach ($banners as $banner) {
            $status = (string) ($banner['status'] ?? 'queued');
            $totalImported += (int) ($banner['imported'] ?? 0);
            $totalPromoted += (int) ($banner['promoted'] ?? 0);

            if ($status === 'completed') {
                $finishedBanners++;
            } elseif ($status === 'failed') {
                $finishedBanners++;
                $failedBanners++;
                if (!empty($banner['error'])) {
                    $errors[] = sprintf('%s: %s', (string) ($banner['name'] ?? $banner['gacha_type']), (string) $banner['error']);
                }
            } elseif ($status === 'running') {
                $runningBanners++;
            }
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $operation['total'] = $totalBanners;
        $operation['processed'] = $finishedBanners;
        $operation['imported'] = $totalImported;
        $operation['promoted'] = $totalPromoted;
        $operation['updated_at'] = $now;
        // An error left by an earlier failed state is stale once a retry succeeds: only the "failed" branch sets it
        $operation['error'] = null;

        if ($totalBanners > 0 && $finishedBanners >= $totalBanners) {
            $operation['percent'] = 100;
            $operation['finished_at'] = $now;
            if ($failedBanners === $totalBanners) {
                $operation['status'] = 'failed';
                $operation['phase'] = 'failed';
                $operation['error'] = implode(' | ', $errors) ?: 'All banner sync streams failed.';
            } else {
                $operation['status'] = 'completed';
                $operation['phase'] = 'completed';
                $operation['warnings'] = $errors;
            }
        } else {
            $operation['status'] = 'running';
            $operation['phase'] = 'syncing';
            // The operation may be resuming after a failure (Messenger retry): drop the closed-state leftovers
            $operation['finished_at'] = null;
            $effectiveProgress = $finishedBanners + ($runningBanners > 0 ? 0.5 : 0.0);
            $operation['percent'] = $totalBanners > 0
                ? min(95, (int) floor(($effectiveProgress / $totalBanners) * 100))
                : 0;
        }

        return $operation;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function saveAndPublish(array $operation): void
    {
        $operationId = (string) $operation['id'];
        $profileId = (int) $operation['profile_id'];

        $opItem = $this->cachePool->getItem(self::KEY_OPERATION_PREFIX . $operationId);
        $opItem->set($operation);
        $opItem->expiresAfter(self::TTL_SECONDS);
        $this->cachePool->save($opItem);

        $profileItem = $this->cachePool->getItem(self::KEY_PROFILE_LATEST_PREFIX . $profileId);
        $profileItem->set($operationId);
        $profileItem->expiresAfter(self::TTL_SECONDS);
        $this->cachePool->save($profileItem);

        try {
            $topic = $this->getProfileTopic($profileId);
            $this->mercureHub->publish(new Update(
                $topic,
                json_encode([
                    'action' => 'pulls:operation_progress',
                    'operation' => $operation,
                ], JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable $e) {
            $this->logger?->warning('PullOperationTracker failed to publish Mercure update', [
                'operation_id' => $operationId,
                'profile_id' => $profileId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
