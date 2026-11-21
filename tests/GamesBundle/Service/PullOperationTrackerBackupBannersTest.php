<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\PerfectworldBundle\Service\Pull\PullOperationTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

/**
 * Multi-file backup imports report per-banner progress; single-file importers keep "banners" null.
 */
class PullOperationTrackerBackupBannersTest extends TestCase
{
    private function createTracker(): PullOperationTracker
    {
        return new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.example.test');
    }

    private static function banners(string $status, int $processed = 0): array
    {
        return ['Lottery_Permanent' => ['gacha_type' => 'Lottery_Permanent', 'name' => 'Standard', 'status' => $status, 'total' => 10, 'processed' => $processed, 'imported' => $processed, 'updated' => 0, 'skipped' => 0, 'error' => null]];
    }

    public function testSingleFileImportsKeepTheLegacyShape(): void
    {
        $operation = $this->createTracker()->startBackupImport(1, 1, 'paimon_moe', 'Paimon.moe', 100);

        $this->assertNull($operation['banners']);
        $this->assertSame(0, $operation['updated']);
    }

    public function testBannerProgressIsTrackedAndNotErasedByLegacyUpdates(): void
    {
        $tracker = $this->createTracker();
        $operation = $tracker->startBackupImport(1, 1, 'nte_exporter', 'nte-exporter', 10, self::banners('queued'));

        $updated = $tracker->updateBackupImportProgress($operation['id'], new PullImportProgress('importing', 10, 4, 3, 0, [], 1, self::banners('running', 4)));
        $this->assertSame('running', $updated['banners']['Lottery_Permanent']['status']);
        $this->assertSame(1, $updated['updated']);

        // A progress without banners (legacy importers) leaves them untouched
        $legacy = $tracker->updateBackupImportProgress($operation['id'], new PullImportProgress('importing', 10, 5, 4, 0));
        $this->assertSame(4, $legacy['banners']['Lottery_Permanent']['processed']);

        $completed = $tracker->completeBackupImport($operation['id'], new PullImportSummary('nte_exporter', 'nte-exporter', 10, 9, 0, [], false, 1, self::banners('completed', 10)));
        $this->assertSame('completed', $completed['status']);
        $this->assertSame('completed', $completed['banners']['Lottery_Permanent']['status']);
        $this->assertSame(1, $completed['updated']);
    }

    public function testFailedOperationClosesUnfinishedBanners(): void
    {
        $tracker = $this->createTracker();
        $operation = $tracker->startBackupImport(1, 1, 'nte_exporter', 'nte-exporter', 10, self::banners('running', 2));

        $failed = $tracker->failOperation($operation['id'], 'Worker crashed');

        $this->assertSame('failed', $failed['banners']['Lottery_Permanent']['status']);
        $this->assertSame('Worker crashed', $failed['banners']['Lottery_Permanent']['error']);
    }
}
