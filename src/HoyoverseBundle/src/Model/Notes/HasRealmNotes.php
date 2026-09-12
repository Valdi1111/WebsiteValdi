<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasRealmNotes
{
    public function getRealmData(): GameNotesRealm;
}