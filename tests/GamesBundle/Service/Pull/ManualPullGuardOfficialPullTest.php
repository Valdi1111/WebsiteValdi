<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The rules on official S rows (built from an official S-rank history). The rules for the other rows are covered,
 * unchanged, by ManualPullGuardTest.
 */
class ManualPullGuardOfficialPullTest extends TestCase
{
    private const int S_MAX_PITY = 90;

    private ManualPullGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ManualPullGuard(new PullGapPlanner());
    }

    public function testThePityOfAnOfficialSCanBeNeitherChangedNorCleared(): void
    {
        // #21 has no pull number: the rule follows the origin, not the number
        foreach ([[20, 41], [20, null], [21, 4], [21, 9], [21, null]] as [$id, $pity]) {
            $this->assertViolation(
                ManualPullViolationException::OFFICIAL_PULL,
                fn () => $this->guard->assertCanDeclare(self::entries(), $id, $pity, self::S_MAX_PITY),
            );
        }

        // A manual S with a pull number keeps the rules of a manual S
        $this->guard->assertCanDeclare(self::entries(), 30, 3, self::S_MAX_PITY);
        $this->assertViolation(
            ManualPullViolationException::PITY_REQUIRED,
            fn () => $this->guard->assertCanDeclare(self::entries(), 30, null, self::S_MAX_PITY),
        );

        // The recorded S of the banner keeps the usual rules
        $this->guard->assertCanDeclare(self::entries(), 3, 50, self::S_MAX_PITY);
        $this->guard->assertCanDeclare(self::entries(), 3, null, self::S_MAX_PITY);
    }

    public function testNoSCanBeAddedRightBeforeAnOfficialS(): void
    {
        foreach ([20, 21] as $anchorId) {
            $this->assertViolation(
                ManualPullViolationException::OFFICIAL_PULL,
                fn () => $this->guard->assertCanCreate(self::entries(), $anchorId, self::date('2025-01-02 11:00:00'), 1, self::S_MAX_PITY, null, 'Nahida', true, self::now()),
            );
        }

        // Before a recorded S, and after the last S, it is still allowed
        $this->guard->assertCanCreate(self::entries(), 3, self::date('2025-01-03 09:00:00'), 1, self::S_MAX_PITY, null, 'Nahida', true, self::now());
        $this->guard->assertCanCreate(self::entries(), null, self::date('2025-01-05 10:00:00'), 1, self::S_MAX_PITY, null, 'Nahida', true, self::now());
    }

    public function testAnOfficialSIsAPossibleDuplicateLikeARecordedOne(): void
    {
        // Before the recorded S #3, less than 24 h after the official Raiden (#21)
        $this->assertViolation(
            ManualPullViolationException::POSSIBLE_DUPLICATE,
            fn () => $this->guard->assertCanCreate(self::entries(), 3, self::date('2025-01-02 13:00:00'), 1, self::S_MAX_PITY, null, 'Raiden', false, self::now()),
            'An official S pull of "Raiden" exists on 2025-01-02 12:00:00. Is this another copy?',
        );

        // Confirmed as another copy, or another item: accepted
        $this->guard->assertCanCreate(self::entries(), 3, self::date('2025-01-02 13:00:00'), 1, self::S_MAX_PITY, null, 'Raiden', true, self::now());
        $this->guard->assertCanCreate(self::entries(), 3, self::date('2025-01-02 13:00:00'), 1, self::S_MAX_PITY, null, 'Nahida', false, self::now());
    }

    /**
     * A recorded S, two official S of one 10-pull (pulls 140 and 144), a recorded pull and S, a manual S with a
     * number (not official: it keeps the manual rules), then the open window.
     *
     * @return list<PullTimelineEntry>
     */
    private static function entries(): array
    {
        return [
            new PullTimelineEntry(1, self::date('2025-01-01 10:00:00'), PullOrigin::Recorded, true, true),
            new PullTimelineEntry(20, self::date('2025-01-02 12:00:00'), PullOrigin::Official, true, true, 40, null, 'Furina', 140),
            new PullTimelineEntry(21, self::date('2025-01-02 12:00:00'), PullOrigin::Official, true, true, 4, null, 'Raiden'),
            new PullTimelineEntry(2, self::date('2025-01-03 10:00:00'), PullOrigin::Recorded, false, true),
            new PullTimelineEntry(3, self::date('2025-01-03 11:00:00'), PullOrigin::Recorded, true, true),
            new PullTimelineEntry(30, self::date('2025-01-04 10:00:00'), PullOrigin::Manual, true, true, 2, null, 'Nahida', 200),
        ];
    }

    private function assertViolation(string $violation, callable $call, ?string $message = null): void
    {
        try {
            $call();
        } catch (ManualPullViolationException $e) {
            $this->assertSame($violation, $e->violation);
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            return;
        }

        $this->fail(\sprintf('Expected a "%s" violation.', $violation));
    }

    private static function now(): \DateTimeImmutable
    {
        return self::date('2025-02-01 00:00:00');
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
