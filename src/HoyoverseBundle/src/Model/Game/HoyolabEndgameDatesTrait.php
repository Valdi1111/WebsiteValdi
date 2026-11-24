<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Dates of the HoYoLAB game records used by the endgames reminders: the {year, month, day, hour, minute} objects are
 * server wall-clock time, read through HoyoverseServerTimezoneResolver, and may come empty.
 */
trait HoyolabEndgameDatesTrait
{
    private ?HoyoverseServerTimezoneResolver $serverTimezoneResolver = null;

    public function getServerTimezoneResolver(): ?HoyoverseServerTimezoneResolver
    {
        return $this->serverTimezoneResolver;
    }

    #[Required]
    public function setServerTimezoneResolver(HoyoverseServerTimezoneResolver $serverTimezoneResolver): void
    {
        $this->serverTimezoneResolver = $serverTimezoneResolver;
    }

    /**
     * Turns the empty date objects of the responses (all null, zeros, missing parts) into null: no date, instead of
     * a denormalization error or a date in year -1. Only the given paths are touched.
     *
     * @param array<string, mixed>|mixed $data
     * @param list<list<string>> $paths
     * @return array<string, mixed>
     */
    private static function withoutEmptyDates(mixed $data, array $paths): array
    {
        if (!is_array($data)) {
            return [];
        }

        foreach ($paths as $path) {
            $parent = &$data;
            foreach (array_slice($path, 0, -1) as $key) {
                if (!isset($parent[$key]) || !is_array($parent[$key])) {
                    unset($parent);
                    continue 2;
                }
                $parent = &$parent[$key];
            }

            $key = end($path);
            if (isset($parent[$key]) && is_array($parent[$key]) && self::isEmptyDate($parent[$key])) {
                $parent[$key] = null;
            }
            unset($parent);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $date {year, month, day, hour, minute, second} object
     */
    private static function isEmptyDate(array $date): bool
    {
        foreach (['year', 'month', 'day', 'hour', 'minute'] as $part) {
            if (!isset($date[$part]) || !is_numeric($date[$part])) {
                return true;
            }
        }

        return (int) $date['year'] <= 0;
    }

    private function toServerInstant(?\DateTimeInterface $wallClock, string $region): ?\DateTimeImmutable
    {
        if ($wallClock === null) {
            return null;
        }

        return $this->getServerTimezoneResolver()->serverWallClockToInstant($wallClock, $region);
    }
}
