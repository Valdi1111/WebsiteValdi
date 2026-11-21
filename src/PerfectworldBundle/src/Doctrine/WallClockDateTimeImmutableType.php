<?php

namespace App\PerfectworldBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * DATETIME column holding a wall-clock time exactly as received (e.g. the game's display time),
 * without any timezone meaning.
 *
 * Values are read back in UTC, which has no DST gaps: with the default timezone (Europe/Rome) a time
 * such as "2027-03-28 02:30:00" would be shifted to 03:30 on read. Write values created in UTC too,
 * so format() stores the same wall-clock string.
 */
class WallClockDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'wall_clock_datetime_immutable';

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
     */
    public static function fromString(string $wallClock): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wallClock, new \DateTimeZone('UTC'));
        if ($dateTime === false || $dateTime->format('Y-m-d H:i:s') !== $wallClock) {
            throw new \InvalidArgumentException(sprintf('Invalid wall-clock time "%s".', $wallClock));
        }

        return $dateTime;
    }
}
