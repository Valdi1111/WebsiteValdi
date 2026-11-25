<?php

namespace App\GamesBundle\Model\Game;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Per game profile cache entry of a raw API payload, shared by the notes, the endgames and the check-in status.
 *
 * An entry is {fetchedAt: unix timestamp, data: raw API payload}, plain arrays only: an entry written before a deploy
 * still reads after it, where a serialized object of an older class shape could fail. The caller denormalizes the
 * data again on every read. Answers the fetch marks as not cacheable (errors throw, partial answers) are returned
 * but not stored. The TTL is the default lifetime of the pool.
 */
final class CachedProfileData
{
    /**
     * The cached entry, or a new API call when there is none or $fresh is set (the call then replaces the entry).
     *
     * @param string|null                                         $key   null to skip the cache (no pool, unsaved profile:
     *                                                                   one shared key would mix several profiles)
     * @param \Closure(): array{0: array<string, mixed>, 1: bool} $fetch calls the API and returns the payload and
     *                                                                   whether it may be cached
     * @return array{fetchedAt: int, data: array<string, mixed>}
     */
    public static function get(?CacheInterface $pool, ?string $key, bool $fresh, \Closure $fetch, ?ClockInterface $clock): array
    {
        $compute = static function (?ItemInterface $item = null, bool &$save = true) use ($fetch, $clock): array {
            [$data, $save] = $fetch();

            return [
                'fetchedAt' => ($clock?->now() ?? new \DateTimeImmutable())->getTimestamp(),
                'data' => $data,
            ];
        };

        if ($pool === null || $key === null) {
            return $compute();
        }

        // INF forces the recompute while keeping the stampede protection of get()
        $entry = $pool->get($key, $compute, $fresh ? INF : null);
        if (!is_array($entry) || !is_int($entry['fetchedAt'] ?? null) || !is_array($entry['data'] ?? null)) {
            // Entry of another shape (written by another version of this class): fetch it again
            $entry = $pool->get($key, $compute, INF);
        }

        return $entry;
    }

    /**
     * Fetch time of an entry, in the timezone of the clock as every other time of the embeds ("@" alone gives UTC).
     *
     * @param array{fetchedAt: int, data: array<string, mixed>} $entry
     */
    public static function fetchedAt(array $entry, ?ClockInterface $clock): \DateTimeImmutable
    {
        $timezone = ($clock?->now() ?? new \DateTimeImmutable())->getTimezone();

        return new \DateTimeImmutable('@' . $entry['fetchedAt'])->setTimezone($timezone);
    }
}
