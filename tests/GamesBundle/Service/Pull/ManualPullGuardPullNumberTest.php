<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Operation A on an S with a pull number (built from an official S-rank history). The rules for rows without a number
 * are covered, unchanged, by ManualPullGuardTest.
 */
class ManualPullGuardPullNumberTest extends TestCase
{
    public function testTheDeclaredPityOfANumberedSCannotBeChangedNorCleared(): void
    {
        $guard = new ManualPullGuard(new PullGapPlanner());
        $entries = [
            new PullTimelineEntry(1, self::date('2025-01-01 10:00:00'), PullOrigin::Recorded, true, true),
            new PullTimelineEntry(20, self::date('2025-01-02 12:00:00'), PullOrigin::Manual, true, true, 40, null, 'Furina', 140),
            new PullTimelineEntry(21, self::date('2025-01-02 12:00:00'), PullOrigin::Manual, true, true, 4, null, 'Raiden', 144),
            new PullTimelineEntry(2, self::date('2025-01-03 10:00:00'), PullOrigin::Recorded, false, true),
            new PullTimelineEntry(3, self::date('2025-01-03 11:00:00'), PullOrigin::Recorded, true, true),
        ];

        foreach ([[20, 41], [20, null], [21, 4], [21, 9]] as [$id, $pity]) {
            try {
                $guard->assertCanDeclare($entries, $id, $pity, 90);
                $this->fail(\sprintf('S #%d has a pull number', $id));
            } catch (ManualPullViolationException $e) {
                $this->assertSame(ManualPullViolationException::OFFICIAL_PULL, $e->violation);
            }
        }

        // The S without a number in the same banner keeps the usual rules
        $guard->assertCanDeclare($entries, 3, 50, 90);
        $guard->assertCanDeclare($entries, 3, null, 90);
        $this->expectException(ManualPullViolationException::class);
        $guard->assertCanDeclare($entries, 3, 1, 90);
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
