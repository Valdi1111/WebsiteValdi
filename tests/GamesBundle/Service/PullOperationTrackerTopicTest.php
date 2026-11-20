<?php

namespace App\Tests\GamesBundle\Service;

use App\GryphlineBundle\Service\Pull\PullOperationTracker as GryphlinePullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker as HoyoversePullOperationTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

/**
 * Profile IDs come from per-provider tables: on the shared games host the Mercure topics
 * must stay distinct for profiles with the same ID in different providers.
 */
class PullOperationTrackerTopicTest extends TestCase
{
    public function testSameProfileIdProducesDistinctTopicsPerProvider(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hoyoverse = new HoyoversePullOperationTracker(new ArrayAdapter(), $hub, 'games.example.test');
        $gryphline = new GryphlinePullOperationTracker(new ArrayAdapter(), $hub, 'games.example.test');

        $this->assertSame('https://games.example.test/hoyoverse/profiles/1/pulls/progress', $hoyoverse->getProfileTopic(1));
        $this->assertSame('https://games.example.test/gryphline/profiles/1/pulls/progress', $gryphline->getProfileTopic(1));
        $this->assertNotSame($hoyoverse->getProfileTopic(1), $gryphline->getProfileTopic(1));
    }

    public function testFallsBackToLocalGamesDomainWhenNotConfigured(): void
    {
        $tracker = new GryphlinePullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), null);

        $this->assertSame('https://games.local/gryphline/profiles/7/pulls/progress', $tracker->getProfileTopic(7));
    }
}
