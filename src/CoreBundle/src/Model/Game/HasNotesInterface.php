<?php

namespace App\CoreBundle\Model\Game;

use App\CoreBundle\Model\GameNotes\GameNotes;

/**
 * @template T of GameNotes
 */
interface HasNotesInterface
{
    public function getUrlNotes(): string;

    /**
     * @param RuntimeAccountDataInterface $account
     * @return T
     */
    public function getNotes(RuntimeAccountDataInterface $account): GameNotes;

    /**
     * @return class-string<T>
     */
    public function getNotesClass(): string;
}
