<?php

namespace App\HoyoverseBundle\Model\Notes;

interface GameNotesMetricInterface
{
    public function isDone(): bool;

    public function getFormattedOutput(): string;

    public function isUnlocked(): bool;
}