<?php

namespace App\HoyoverseBundle\Model\Notes;

interface HasExpeditionsNotes
{
    public function getExpeditionsData(): GameNotesExpeditions;
}