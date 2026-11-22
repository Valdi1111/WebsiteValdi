<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

/**
 * The S-rank history import is tracked per banner, and moves on through the backup import progress methods.
 */
class PullOperationTrackerSRankHistoryTest extends TestCase
{
    public function testAnSRankHistoryImportIsTrackedPerBanner(): void
    {
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.example.test');

        $operation = $tracker->startSRankHistoryImport(10, 1, ['2' => 'Exclusive Channel', '1' => 'Standard Channel']);

        $this->assertSame(PullOperationKind::S_RANK_HISTORY_IMPORT->value, $operation['kind']);
        $this->assertSame('Official S-Rank History', $operation['label']);
        $this->assertSame(['queued', 2, 0], [$operation['status'], $operation['total'], $operation['updated']]);
        $this->assertSame(['gacha_type' => '2', 'name' => 'Exclusive Channel', 'status' => 'queued', 'imported' => 0, 'updated' => 0, 'error' => null], $operation['banners']['2']);
        $this->assertTrue($tracker->hasActiveOperation(10, PullOperationKind::S_RANK_HISTORY_IMPORT));
        $this->assertFalse($tracker->hasActiveBackupImport(10), 'Another kind');
        $this->assertFalse($tracker->hasActiveOperation(11, PullOperationKind::S_RANK_HISTORY_IMPORT));

        $banners = $operation['banners'];
        $banners['2'] = [...$banners['2'], 'status' => 'completed', 'imported' => 3, 'updated' => 1];
        $running = $tracker->updateBackupImportProgress($operation['id'], new PullImportProgress('importing', 2, 1, 3, 0, [], 1, $banners));
        $this->assertSame(['running', 'importing', 50, 3, 1], [$running['status'], $running['phase'], $running['percent'], $running['imported'], $running['updated']]);

        $banners['1'] = [...$banners['1'], 'status' => 'skipped', 'error' => 'A conflict'];
        $completed = $tracker->completeBackupImport($operation['id'], new PullImportSummary('s_rank_history', 'Official S-Rank History', 2, 3, 1, ['Standard Channel: not imported: A conflict'], false, 1, $banners));
        $this->assertSame(['completed', 100, 2, 3, 1, 1], [$completed['status'], $completed['percent'], $completed['processed'], $completed['imported'], $completed['skipped'], $completed['updated']]);
        $this->assertSame('skipped', $completed['banners']['1']['status']);
        $this->assertFalse($tracker->hasActiveOperation(10, PullOperationKind::S_RANK_HISTORY_IMPORT), 'Finished');
    }
}
