<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;

/**
 * @template T of GameNotes
 */
interface HasNotesInterface
{
    public function getUrlNotes(): string;

    /**
     * @param RuntimeAccountData $account
     * @return T
     */
    public function getNotes(RuntimeAccountData $account): GameNotes;

    /**
     * @return class-string<T>
     */
    public function getNotesClass(): string;
}