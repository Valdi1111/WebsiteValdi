<?php

namespace App\CoreBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * DATETIME column holding a wall-clock time exactly as received (e.g. a game server's display time, or
 * the UTC time of an API timestamp), without any timezone meaning attached to the stored digits.
 *
 * Values are read back tagged as UTC, which has no DST gaps: with the default timezone (Europe/Rome) a
 * time such as "2026-03-29 02:30:00" would otherwise be shifted to 03:30 on read. Write values tagged
 * as UTC too (fromString(), fromDateTime()), so format() stores the same wall-clock string.
 */
class WallClockDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'wall_clock_datetime_immutable';
    public const string FORMAT = 'Y-m-d H:i:s';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $utc = new \DateTimeZone('UTC');
        $dateTime = \DateTimeImmutable::createFromFormat('!' . $platform->getDateTimeFormatString(), $value, $utc);
        if ($dateTime !== false) {
            return $dateTime;
        }

        try {
            return new \DateTimeImmutable($value, $utc);
        } catch (\Exception $e) {
            throw InvalidFormat::new($value, static::class, $platform->getDateTimeFormatString(), $e);
        }
    }

    /**
     * Builds a wall-clock value from a "Y-m-d H:i:s" string, keeping the digits unchanged.
     *
     * @throws \InvalidArgumentException when the string is not a valid "Y-m-d H:i:s" time
     */
    public static function fromString(string $wallClock): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $wallClock, new \DateTimeZone('UTC'));
        if ($dateTime === false || $dateTime->format(self::FORMAT) !== $wallClock) {
            throw new \InvalidArgumentException(sprintf('Invalid wall-clock time "%s".', $wallClock));
        }

        return $dateTime;
    }

    /**
     * The same wall-clock digits (to the second) tagged as UTC, whatever timezone the value carries,
     * e.g. a date built in the default timezone by a caller or a test.
     */
    public static function fromDateTime(\DateTimeInterface $dateTime): \DateTimeImmutable
    {
        return self::fromString($dateTime->format(self::FORMAT));
    }

    /**
     * Current local time of the server (default timezone) as a wall-clock value, comparable with values
     * stored in the server's local convention. Callers storing another convention (game server time, UTC)
     * build their own "now", and callers that reject future dates add their own tolerance.
     */
    public static function now(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        return self::fromString(($now ?? new \DateTimeImmutable())->format(self::FORMAT));
    }
}
