<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use Psr\Clock\ClockInterface;

/**
 * Resolves HoYoverse fixed server UTC offsets (no DST) so imported UTC timestamps
 * match the server-local wall-clock times returned directly by HoYoverse's gacha APIs.
 */
class HoyoverseServerTimezoneResolver
{
    /** Without a known region, "now" is shifted by this much: no server is ahead of UTC by more */
    private const string UNKNOWN_REGION_TOLERANCE = '+24 hours';

    public function __construct(
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function getServerTimezoneForProfile(HoyoverseGameProfile $profile): \DateTimeZone
    {
        return $this->getServerTimezoneForRegion((string) $profile->getRegion());
    }

    public function getServerTimezoneForRegion(string $region): \DateTimeZone
    {
        $normalized = strtolower(trim($region));

        // Europe servers: os_euro, prod_official_eur, prod_gf_eu, eur01 -> fixed UTC+1
        if (str_contains($normalized, 'eur') || str_ends_with($normalized, '_eu') || $normalized === 'eu') {
            return new \DateTimeZone('+01:00');
        }

        // America servers: os_usa, prod_official_usa, prod_gf_us, usa01 -> fixed UTC-5
        if (str_contains($normalized, 'usa') || str_ends_with($normalized, '_us') || $normalized === 'na') {
            return new \DateTimeZone('-05:00');
        }

        // Asia / TW / HK / MO / JP / SG / CN servers -> fixed UTC+8
        return new \DateTimeZone('+08:00');
    }

    /**
     * Converts a UTC DateTimeImmutable into a server-local wall-clock DateTimeImmutable
     * tagged as UTC (storage convention of the pulled_at wall-clock column).
     */
    public function utcToServerWallClock(\DateTimeImmutable $utcDateTime, HoyoverseGameProfile $profile): \DateTimeImmutable
    {
        return WallClockDateTimeImmutableType::fromDateTime($utcDateTime->setTimezone($this->getServerTimezoneForProfile($profile)));
    }

    /**
     * Reads the date and time digits of a server wall-clock value (e.g. the {year, month, day, ...} objects of
     * the HoYoLAB game records, whatever timezone they were parsed in) as the server time of the region, giving
     * the real instant.
     */
    public function serverWallClockToInstant(\DateTimeInterface $wallClock, string $region): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), $this->getServerTimezoneForRegion($region));
    }

    /**
     * Parses a wall-clock string already in server time (e.g. paimon.moe) using the
     * same storage convention as HoyoversePullItem.
     */
    public function parseServerWallClock(string $wallClockString): ?\DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($wallClockString), new \DateTimeZone('UTC'));

        return $dt ?: null;
    }

    /**
     * Current server wall-clock time of the profile, tagged as UTC like the stored pulls (the upper
     * limit of manual pull dates). Without a region the server offset is unknown, so the current UTC
     * time plus a 24 h tolerance is returned instead.
     */
    public function getServerWallClockNow(HoyoverseGameProfile $profile): \DateTimeImmutable
    {
        $utcNow = ($this->clock?->now() ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        if (trim((string) $profile->getRegion()) === '') {
            $utcNow = $utcNow->modify(self::UNKNOWN_REGION_TOLERANCE);
        } else {
            $utcNow = $utcNow->setTimezone($this->getServerTimezoneForProfile($profile));
        }

        // Same digits tagged as UTC, without microseconds
        return WallClockDateTimeImmutableType::fromDateTime($utcNow);
    }
}
