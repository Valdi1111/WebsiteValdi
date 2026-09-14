<?php

namespace App\HoyoverseBundle\Model\Notes;

interface GameNotesMetricInterface
{
    public function isDone(): bool;

    public function getFormattedOutput(): string;

    public function isUnlocked(): bool;

    public function getCheckType(): GameNotesMetricCheckType;

    public function getOutputType(): GameNotesMetricOutputType;
}