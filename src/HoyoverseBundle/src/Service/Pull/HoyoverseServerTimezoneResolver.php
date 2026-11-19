<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;

/**
 * Resolves HoYoverse fixed server UTC offsets (no DST) so imported UTC timestamps
 * match the server-local wall-clock times returned directly by HoYoverse's gacha APIs.
 */
class HoyoverseServerTimezoneResolver
{
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
     * tagged as UTC (matching HoyoversePullItem storage convention).
     */
    public function utcToServerWallClock(\DateTimeImmutable $utcDateTime, HoyoverseGameProfile $profile): \DateTimeImmutable
    {
        $serverTz = $this->getServerTimezoneForProfile($profile);
        $wallClock = $utcDateTime->setTimezone($serverTz)->format('Y-m-d H:i:s');

        $converted = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wallClock, new \DateTimeZone('UTC'));

        return $converted ?: $utcDateTime;
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
}
