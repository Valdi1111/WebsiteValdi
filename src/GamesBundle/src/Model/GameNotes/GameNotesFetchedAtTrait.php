<?php

namespace App\GamesBundle\Model\GameNotes;

use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Fetch time of the notes (see GameNotes), set by the provider NotesTrait after the denormalization.
 */
trait GameNotesFetchedAtTrait
{
    #[Ignore]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[Ignore]
    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    #[Ignore]
    public function setFetchedAt(?\DateTimeImmutable $fetchedAt): static
    {
        $this->fetchedAt = $fetchedAt;

        return $this;
    }

    /**
     * Instant $seconds after the fetch: turns a relative time of the payload into an absolute one, which stays
     * right however old the cached notes are. Null when either is unknown.
     */
    public function timeAfterFetch(int|string|null $seconds): ?\DateTimeImmutable
    {
        if ($this->fetchedAt === null || $seconds === null || !is_numeric($seconds)) {
            return null;
        }

        return $this->fetchedAt->modify(sprintf('+%d seconds', max(0, (int) $seconds)));
    }
}
