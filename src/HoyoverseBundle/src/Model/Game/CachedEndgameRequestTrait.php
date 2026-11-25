<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\CachedProfileData;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Per game profile cache of the raw battle record payloads (one entry per request: url and query), shared by the
 * endgames traits of the HoYoLAB games, on CachedProfileData like the notes.
 *
 * The fetch time is read with the notes clock ($notesClock of CachedNotesTrait): every service with endgames also
 * uses NotesTrait. The TTL is the default lifetime of the pool.
 *
 * @mixin GameInterface
 */
trait CachedEndgameRequestTrait
{
    private ?CacheInterface $endgamesCache = null;

    public function getEndgamesCache(): ?CacheInterface
    {
        return $this->endgamesCache;
    }

    #[Required]
    public function setEndgamesCache(
        #[Target('hoyoverse.endgames.cache')]
        CacheInterface $cache
    ): void
    {
        $this->endgamesCache = $cache;
    }

    /**
     * Raw "data" of a battle record GET request with its fetch time: the cached one, or a new request when there is
     * none or $fresh is set (the answer then replaces the entry). Only an answer with retcode 0 and a data array is
     * cached; errors throw as before and are never cached.
     *
     * @param array<string, int|string>                                     $query
     * @param class-string<\App\HoyoverseBundle\Exception\HoyolabException> $exceptionClass
     * @param bool                                                          $withDs send a new DS header (Star Rail)
     * @return array{fetchedAt: int, data: array<string, mixed>} fetchedAt is a unix timestamp; data is [] when the
     *                                                           answer has none
     */
    private function requestCachedEndgame(
        RuntimeAccountDataInterface $account,
        string $url,
        array $query,
        string $exceptionClass,
        bool $fresh = false,
        bool $withDs = false,
    ): array
    {
        $profileId = $account->getGameProfile()->getId();
        // One entry per profile and request; the DS header changes on every call and stays out of the key
        $key = $profileId !== null ? sprintf('endgame_%d_%s', $profileId, hash('xxh128', $url . '?' . http_build_query($query))) : null;

        return CachedProfileData::get($this->endgamesCache, $key, $fresh, function () use ($account, $url, $query, $exceptionClass, $withDs): array {
            $body = $this->requestHoyolab(
                method: Request::METHOD_GET,
                url: $url,
                auth: $account,
                query: $query,
                exceptionClass: $exceptionClass,
                extraHeaders: $withDs ? ['DS' => $this->getHoyolabUtils()->generateDS()] : []
            );

            $data = $body['data'] ?? null;

            // A body without a retcode does not throw but is not a success: used as it is, not cached
            return [is_array($data) ? $data : [], ($body['retcode'] ?? null) === 0 && is_array($data)];
        }, $this->notesClock);
    }

    /**
     * Sets on every endgame of a mode the fetch time of the entries it was built from, the oldest when there are
     * several.
     *
     * @param list<GameEndgame>                                       $endgames
     * @param array{fetchedAt: int, data: array<string, mixed>}       $entry
     * @param array{fetchedAt: int, data: array<string, mixed>}       ...$entries
     * @return list<GameEndgame>
     */
    private function withEndgameFetchTime(array $endgames, array $entry, array ...$entries): array
    {
        $oldest = min(array_column([$entry, ...$entries], 'fetchedAt'));
        $fetchedAt = CachedProfileData::fetchedAt(['fetchedAt' => $oldest, 'data' => []], $this->notesClock);

        foreach ($endgames as $endgame) {
            $endgame->setFetchedAt($fetchedAt);
        }

        return $endgames;
    }
}
