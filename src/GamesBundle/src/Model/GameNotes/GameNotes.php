<?php

namespace App\GamesBundle\Model\GameNotes;

/**
 * Real-time notes of a game profile, denormalized from the provider payload.
 *
 * The fetch time is the moment the payload was read from the API, kept with the cached entry: relative values of
 * the payload (seconds until the stamina is full...) are relative to it, not to the moment the notes are read.
 * Implementations use GameNotesFetchedAtTrait.
 */
interface GameNotes
{
    public function getFetchedAt(): ?\DateTimeImmutable;

    public function setFetchedAt(?\DateTimeImmutable $fetchedAt): static;
}
