<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\GameNotes\GameNotes;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Per game profile cache of the raw notes payload, shared by the bundle NotesTrait implementations, on the entry rules
 * of CachedProfileData ({fetchedAt, data}, plain arrays, errors and partial answers not stored). The notes object is
 * denormalized again on every read and gets the fetch time of its entry (GameNotes::getFetchedAt()). The TTL is the
 * default lifetime of the pool, which the bundle trait injects through its own setNotesCache().
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
     * Raw notes payload of the profile with its fetch time: the cached one, or a new API call when there is none or
     * $fresh is set.
     *
     * @param \Closure(): array{0: array<string, mixed>, 1: bool} $fetch calls the API and returns the payload and whether it may be cached
     * @return array{fetchedAt: int, data: array<string, mixed>} fetchedAt is a unix timestamp
     */
    private function getCachedNotesEntry(RuntimeAccountDataInterface $account, bool $fresh, \Closure $fetch): array
    {
        $profileId = $account->getGameProfile()->getId();

        return CachedProfileData::get($this->notesCache, $profileId !== null ? 'notes_' . $profileId : null, $fresh, $fetch, $this->notesClock);
    }

    /**
     * @template N of GameNotes
     * @param N $notes
     * @param array{fetchedAt: int, data: array<string, mixed>} $entry
     * @return N
     */
    private function withFetchTime(GameNotes $notes, array $entry): GameNotes
    {
        return $notes->setFetchedAt(CachedProfileData::fetchedAt($entry, $this->notesClock));
    }
}
