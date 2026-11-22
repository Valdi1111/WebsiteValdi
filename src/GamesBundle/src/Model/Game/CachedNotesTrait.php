<?php

namespace App\GamesBundle\Model\Game;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Per game profile cache of the raw notes payload, shared by the bundle NotesTrait implementations.
 *
 * An entry is {fetchedAt: unix timestamp, data: raw API payload}, plain arrays only: an entry written
 * before a deploy still reads after it, where a serialized notes object of an older class shape could fail.
 * The notes object is denormalized again on every read. The TTL is the default lifetime of the pool,
 * which the bundle trait injects through its own setNotesCache().
 */
trait CachedNotesTrait
{
    private ?CacheInterface $notesCache = null;
    private ?ClockInterface $notesClock = null;

    public function getNotesCache(): ?CacheInterface
    {
        return $this->notesCache;
    }

    #[Required]
    public function setNotesClock(ClockInterface $clock): void
    {
        $this->notesClock = $clock;
    }

    /**
     * Raw notes payload of the profile: the cached one, or a new API call when there is none or $fresh is set.
     *
     * @param \Closure(): array{0: array<string, mixed>, 1: bool} $fetch calls the API and returns the payload and whether it may be cached
     * @return array<string, mixed>
     */
    private function getCachedNotesData(RuntimeAccountDataInterface $account, bool $fresh, \Closure $fetch): array
    {
        $profileId = $account->getGameProfile()->getId();
        // No pool (service built by hand) or no id (unsaved profile, one shared key would mix the notes of several profiles)
        if ($this->notesCache === null || $profileId === null) {
            return $fetch()[0];
        }

        $compute = function (ItemInterface $item, bool &$save) use ($fetch): array {
            [$data, $save] = $fetch();

            return [
                'fetchedAt' => ($this->notesClock?->now() ?? new \DateTimeImmutable())->getTimestamp(),
                'data' => $data,
            ];
        };

        $key = 'notes_' . $profileId;
        // INF forces the recompute while keeping the stampede protection of get()
        $entry = $this->notesCache->get($key, $compute, $fresh ? INF : null);
        if (!is_array($entry) || !is_int($entry['fetchedAt'] ?? null) || !is_array($entry['data'] ?? null)) {
            // Entry of another shape (written by another version of this trait): fetch it again
            $entry = $this->notesCache->get($key, $compute, INF);
        }

        return $entry['data'];
    }
}
