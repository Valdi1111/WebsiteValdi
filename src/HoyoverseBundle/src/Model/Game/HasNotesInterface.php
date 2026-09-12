<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\Notes\GameNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasNotesInterface
{
    public function getUrlNotes(): string;

    public function getNotes(RuntimeAccountData $account): GameNotes;

    /**
     * @return class-string<GameNotes>
     */
    public function getNotesClass(): string;
}