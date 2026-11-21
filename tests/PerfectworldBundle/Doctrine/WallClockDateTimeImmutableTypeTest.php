<?php

namespace App\Tests\PerfectworldBundle\Doctrine;

use App\PerfectworldBundle\Doctrine\WallClockDateTimeImmutableType;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\TestCase;

/**
 * The game display time must survive a database round trip unchanged, including the hour that does
 * not exist in Europe/Rome when DST starts.
 */
class WallClockDateTimeImmutableTypeTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
    }

    public function testDstGapTimeRoundTripsUnchanged(): void
    {
        $type = new WallClockDateTimeImmutableType();
        $platform = new MySQL80Platform();

        $value = WallClockDateTimeImmutableType::fromString('2027-03-28 02:30:00');
        $stored = $type->convertToDatabaseValue($value, $platform);
        $read = $type->convertToPHPValue($stored, $platform);

        $this->assertSame('2027-03-28 02:30:00', $stored);
        $this->assertSame('2027-03-28 02:30:00', $read?->format('Y-m-d H:i:s'));
    }

    public function testDefaultTimezoneParsingWouldShiftTheSameValue(): void
    {
        // Documents the problem the type avoids
        $shifted = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2027-03-28 02:30:00');

        $this->assertSame('2027-03-28 03:30:00', $shifted->format('Y-m-d H:i:s'));
    }

    public function testInvalidWallClockIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WallClockDateTimeImmutableType::fromString('2026-13-40 25:00:00');
    }
}
