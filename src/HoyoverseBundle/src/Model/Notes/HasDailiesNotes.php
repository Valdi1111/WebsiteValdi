<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasDailiesNotes
{
    public function getDailiesData(): GameNotesDailies;
}