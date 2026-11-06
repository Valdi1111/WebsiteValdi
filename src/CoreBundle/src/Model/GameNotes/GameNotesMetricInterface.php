<?php

namespace App\CoreBundle\Model\GameNotes;

interface GameNotesMetricInterface
{
    public function isDone(): bool;

    public function getFormattedOutput(): string;

    public function isUnlocked(): bool;

    public function getName(): ?string;
}
