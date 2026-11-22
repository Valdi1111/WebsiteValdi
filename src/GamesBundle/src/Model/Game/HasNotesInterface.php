<?php

namespace App\GamesBundle\Model\Game;

use App\GamesBundle\Model\GameNotes\GameNotes;

/**
 * @template T of GameNotes
 */
interface HasNotesInterface
{
    public function getUrlNotes(): string;

    /**
     * Notes of the game profile, read from the per-profile notes cache when a recent copy is there.
     *
     * @param RuntimeAccountDataInterface $account
     * @param bool $fresh skip the cached copy: call the API and store its answer (e.g. a manual refresh)
     * @return T
     */
    public function getNotes(RuntimeAccountDataInterface $account, bool $fresh = false): GameNotes;

    /**
     * @return class-string<T>
     */
    public function getNotesClass(): string;
}
