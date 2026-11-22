<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

/**
 * A sync page that throws is retried by Messenger: a late success must not leave stale failure state behind.
 */
class PullOperationTrackerSyncRetryTest extends TestCase
{
    private function createTracker(): PullOperationTracker
    {
        return new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.example.test');
    }

    public function testOperationResumedByARetryDropsTheClosedState(): void
    {
        $tracker = $this->createTracker();
        $id = $tracker->startSyncOperation(1, 1, PullOperationKind::STOKEN_SYNC, ['a' => 'Banner A', 'b' => 'Banner B'])['id'];

        $tracker->failSyncBanner($id, 'a', 'timeout');
        $failed = $tracker->failSyncBanner($id, 'b', 'timeout');
        $this->assertSame('failed', $failed['status']);
        $this->assertNotNull($failed['finished_at']);

        $resumed = $tracker->recordSyncBannerPage($id, 'a', 3, 0, false);

        $this->assertSame('running', $resumed['status']);
        $this->assertNull($resumed['finished_at']);
        $this->assertNull($resumed['error']);
        $this->assertNull($resumed['banners']['a']['error']);
        $this->assertNotNull($tracker->getActiveOperationForProfile(1));
    }

    public function testRecoveredBannerNoLongerReportsAWarning(): void
    {
        $tracker = $this->createTracker();
        $id = $tracker->startSyncOperation(1, 1, PullOperationKind::STOKEN_SYNC, ['a' => 'Banner A', 'b' => 'Banner B'])['id'];

        $tracker->recordSyncBannerPage($id, 'b', 2, 0, true);
        $partial = $tracker->failSyncBanner($id, 'a', 'timeout');
        $this->assertSame('completed', $partial['status']);
        $this->assertCount(1, $partial['warnings']);

        $recovered = $tracker->recordSyncBannerPage($id, 'a', 1, 0, true);

        $this->assertSame('completed', $recovered['status']);
        $this->assertSame([], $recovered['warnings']);
        $this->assertNull($recovered['banners']['a']['error']);
    }

    public function testRetryThatFinishesTheBannerClearsTheOperationError(): void
    {
        $tracker = $this->createTracker();
        $id = $tracker->startSyncOperation(1, 1, PullOperationKind::STOKEN_SYNC, ['a' => 'Banner A'])['id'];

        $failed = $tracker->failSyncBanner($id, 'a', 'timeout');
        $this->assertSame('failed', $failed['status']);
        $this->assertNotNull($failed['error']);

        // Incremental sync: the retried page is usually the last one, so the operation goes from failed to completed
        $recovered = $tracker->recordSyncBannerPage($id, 'a', 1, 0, true);

        $this->assertSame('completed', $recovered['status']);
        $this->assertNull($recovered['error']);
        $this->assertSame([], $recovered['warnings']);
        $this->assertNotNull($recovered['finished_at']);
    }
}
