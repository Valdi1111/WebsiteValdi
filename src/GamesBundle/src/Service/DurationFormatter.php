<?php

namespace App\GamesBundle\Service;

/**
 * Formats a duration in seconds for the notifications, e.g. "2 days, 3 hr, 15 min, 0 sec".
 */
class DurationFormatter
{
    private const int DAY = 86400;
    private const int HOUR = 3600;
    private const int MINUTE = 60;

    /**
     * With $maxUnits only the largest units are kept: "2 days, 3 hr" for 2.
     */
    public function format(float $seconds = 0, ?int $maxUnits = null): string
    {
        $array = [];

        if ($seconds >= self::DAY) {
            $days = (int) floor($seconds / self::DAY);
            $array[] = $days === 1 ? '1 day' : "{$days} days";
            $seconds -= ($days * self::DAY);
        }
        if ($seconds >= self::HOUR) {
            $hr = (int) floor($seconds / self::HOUR);
            $array[] = "{$hr} hr";
            $seconds -= ($hr * self::HOUR);
        }
        if ($seconds >= self::MINUTE) {
            $min = (int) floor($seconds / self::MINUTE);
            $array[] = "{$min} min";
            $seconds -= ($min * self::MINUTE);
        }
        if ($seconds >= 0 || empty($array)) {
            $array[] = round($seconds, 3) . ' sec';
        }

        if ($maxUnits !== null) {
            $array = array_slice($array, 0, max(1, $maxUnits));
        }

        return implode(', ', $array);
    }
}
