<?php

namespace App\Tests\GamesBundle\Service\Pull;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\PullTimelineEntry;
use App\GamesBundle\Service\Pull\ManualPullGuard;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManualPullGuardTest extends TestCase
{
    private const int S_MAX_PITY = 90;

    private ManualPullGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ManualPullGuard(new PullGapPlanner());
    }

    #[DataProvider('invalidTargetProvider')]
    public function testDeclareRejectsAnythingButAnS(int $id): void
    {
        $this->assertViolation(
            ManualPullViolationException::INVALID_TARGET,
            fn () => $this->guard->assertCanDeclare(self::entries(), $id, 50, self::S_MAX_PITY),
        );
    }

    public function testDeclareRequiresAPityOnAManualS(): void
    {
        $this->assertViolation(
            ManualPullViolationException::PITY_REQUIRED,
            fn () => $this->guard->assertCanDeclare(self::entries(), 30, null, self::S_MAX_PITY),
        );
    }

    public function testDeclareRejectsAPityOutOfRange(): void
    {
        // Window of #4: #3 and #4 are recorded, so the pity is at least 2
        $exception = $this->assertViolation(
            ManualPullViolationException::PITY_OUT_OF_RANGE,
            fn () => $this->guard->assertCanDeclare(self::entries(), 4, 1, self::S_MAX_PITY),
        );
        $this->assertStringContainsString('between 2', $exception->getMessage());
        $this->assertStringContainsString('and 90', $exception->getMessage());

        $this->assertViolation(
            ManualPullViolationException::PITY_OUT_OF_RANGE,
            fn () => $this->guard->assertCanDeclare(self::entries(), 4, 91, self::S_MAX_PITY),
        );
    }

    public function testDeclareRejectsFillersWithoutRoom(): void
    {
        $this->assertViolation(
            ManualPullViolationException::NO_ROOM,
            fn () => $this->guard->assertCanDeclare(self::entries(), 8, 5, self::S_MAX_PITY),
        );
    }

    public function testDeclareAcceptsValidPities(): void
    {
        $entries = self::entries();

        $this->guard->assertCanDeclare($entries, 2, null, self::S_MAX_PITY);
        $this->guard->assertCanDeclare($entries, 2, 2, self::S_MAX_PITY);
        $this->guard->assertCanDeclare($entries, 4, 74, self::S_MAX_PITY);
        $this->guard->assertCanDeclare($entries, 4, 90, self::S_MAX_PITY);
        $this->guard->assertCanDeclare($entries, 30, 50, self::S_MAX_PITY);
        // No room, but nothing to add either
        $this->guard->assertCanDeclare($entries, 8, 2, self::S_MAX_PITY);

        $this->addToAssertionCount(6);
    }

    #[DataProvider('invalidTargetProvider')]
    public function testCreateRejectsAnInvalidAnchor(int $anchorId): void
    {
        $this->assertViolation(
            ManualPullViolationException::INVALID_ANCHOR,
            fn () => $this->create(anchorId: $anchorId, pulledAt: '2025-01-01 11:59:00'),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidTargetProvider(): iterable
    {
        yield 'missing' => [999];
        yield 'not an S' => [3];
        yield 'filler' => [31];
    }

    public function testCreateRejectsAPityOutOfRange(): void
    {
        $this->assertViolation(
            ManualPullViolationException::PITY_OUT_OF_RANGE,
            fn () => $this->create(anchorId: 4, pulledAt: '2025-01-01 11:59:00', pity: 0),
        );
        $exception = $this->assertViolation(
            ManualPullViolationException::PITY_OUT_OF_RANGE,
            fn () => $this->create(anchorId: 4, pulledAt: '2025-01-01 11:59:00', pity: 91),
        );
        $this->assertStringContainsString('between 1 and 90', $exception->getMessage());
    }

    public function testCreateRejectsADateOutsideTheWindow(): void
    {
        // Window of #4: after #2 (11:00:00), before #3 (12:00:00), bounds excluded
        foreach (['2025-01-01 10:30:00', '2025-01-01 11:00:00', '2025-01-01 12:00:00', '2025-01-01 12:30:00'] as $pulledAt) {
            $exception = $this->assertViolation(
                ManualPullViolationException::DATE_OUT_OF_RANGE,
                fn () => $this->create(anchorId: 4, pulledAt: $pulledAt),
            );
            $this->assertSame('The pull date must be after 2025-01-01 11:00:00 and before 2025-01-01 12:00:00.', $exception->getMessage());
        }

        // Open window: after #8, before #9
        $this->assertViolation(
            ManualPullViolationException::DATE_OUT_OF_RANGE,
            fn () => $this->create(anchorId: null, pulledAt: '2025-01-03 00:00:00'),
        );
    }

    public function testCreateRejectsADateInTheFuture(): void
    {
        $entries = [self::recordedS(1, '2025-01-01 10:00:00')];

        $this->assertViolation(
            ManualPullViolationException::DATE_IN_FUTURE,
            fn () => $this->create(anchorId: null, pulledAt: '2025-01-01 13:00:00', now: '2025-01-01 12:00:00', entries: $entries),
        );

        // A shifted "now" gives the tolerance
        $this->create(anchorId: null, pulledAt: '2025-01-01 13:00:00', now: '2025-01-02 12:00:00', entries: $entries);
    }

    public function testCreateRejectsFillersWithoutRoom(): void
    {
        $this->assertViolation(
            ManualPullViolationException::NO_ROOM,
            fn () => $this->create(anchorId: 4, pulledAt: '2025-01-01 11:00:01', pity: 5),
        );

        // Pity 1: no filler needed
        $this->create(anchorId: 4, pulledAt: '2025-01-01 11:00:01', pity: 1);
    }

    public function testCreateFlagsAPossibleDuplicate(): void
    {
        // Same item id as the recorded #2, 2 hours before it
        $exception = $this->assertViolation(
            ManualPullViolationException::POSSIBLE_DUPLICATE,
            fn () => $this->create(anchorId: 2, pulledAt: '2025-01-01 09:00:00', itemId: '1046', name: 'Whatever'),
        );
        $this->assertStringContainsString('Furina', $exception->getMessage());

        // Same normalized name when an item id is missing
        $this->assertViolation(
            ManualPullViolationException::POSSIBLE_DUPLICATE,
            fn () => $this->create(anchorId: 2, pulledAt: '2025-01-01 09:00:00', name: 'furina'),
        );

        // Confirmed as another copy
        $this->create(anchorId: 2, pulledAt: '2025-01-01 09:00:00', itemId: '1046', name: 'Furina', allowDuplicate: true);
    }

    public function testCreateIgnoresOtherItemsDistantPullsAndManualRows(): void
    {
        $this->create(anchorId: 2, pulledAt: '2025-01-01 09:00:00', name: 'Neuvillette Two');
        $this->create(anchorId: 2, pulledAt: '2025-01-01 09:00:00', itemId: '1047', name: 'Furina');
        // 24 h and 1 s before #2
        $this->create(anchorId: 2, pulledAt: '2024-12-31 10:59:59', name: 'Furina');
        // #30 is a manual Raiden Shogun
        $this->create(anchorId: 30, pulledAt: '2025-01-01 13:20:00', name: 'Raiden Shogun');

        $this->addToAssertionCount(4);
    }

    public function testCreateAcceptsValidRequests(): void
    {
        $this->create(anchorId: 4, pulledAt: '2025-01-01 11:59:00');
        $this->create(anchorId: 4, pulledAt: '2025-01-01 11:00:02', pity: 90);
        $this->create(anchorId: null, pulledAt: '2025-01-02 12:00:00');

        $this->addToAssertionCount(3);
    }

    private function create(
        ?int $anchorId,
        string $pulledAt,
        int $pity = 50,
        ?string $itemId = null,
        string $name = 'Nahida',
        bool $allowDuplicate = false,
        string $now = '2025-02-01 00:00:00',
        ?array $entries = null,
    ): void {
        $this->guard->assertCanCreate(
            $entries ?? self::entries(),
            $anchorId,
            self::date($pulledAt),
            $pity,
            self::S_MAX_PITY,
            $itemId,
            $name,
            $allowDuplicate,
            self::date($now),
        );
    }

    private function assertViolation(string $violation, callable $callback): ManualPullViolationException
    {
        try {
            $callback();
        } catch (ManualPullViolationException $exception) {
            $this->assertSame($violation, $exception->violation, $exception->getMessage());

            return $exception;
        }

        $this->fail(\sprintf('Expected a "%s" violation.', $violation));
    }

    /**
     * Windows: #2 (no previous S, recorded 2), #4 (recorded 2, room between #2 and #3),
     * #30 manual (alone, with a filler), #6 (recorded 2), #8 (neighbours 1 s apart: no room),
     * then the open window with #9.
     *
     * @return list<PullTimelineEntry>
     */
    private static function entries(): array
    {
        return [
            self::recorded(1, '2025-01-01 10:00:00'),
            self::recordedS(2, '2025-01-01 11:00:00', itemId: '1046', name: 'Furina'),
            self::recorded(3, '2025-01-01 12:00:00'),
            self::recordedS(4, '2025-01-01 13:00:00', name: 'Neuvillette'),
            new PullTimelineEntry(31, self::date('2025-01-01 13:15:00'), PullOrigin::Filler, false, true),
            new PullTimelineEntry(30, self::date('2025-01-01 13:30:00'), PullOrigin::Manual, true, true, 40, null, 'Raiden Shogun'),
            self::recorded(5, '2025-01-02 00:00:00'),
            self::recordedS(6, '2025-01-02 00:00:01'),
            self::recorded(7, '2025-01-02 00:00:02'),
            self::recordedS(8, '2025-01-02 00:00:02'),
            self::recorded(9, '2025-01-03 00:00:00'),
        ];
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private static function recorded(int $id, string $at): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Recorded, false, true);
    }

    private static function recordedS(int $id, string $at, ?string $itemId = null, ?string $name = null): PullTimelineEntry
    {
        return new PullTimelineEntry($id, self::date($at), PullOrigin::Recorded, true, true, null, $itemId, $name);
    }
}
