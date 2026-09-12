<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasWeekliesNotes
{
    public function getWeekliesData(): GameNotesWeeklies;
}