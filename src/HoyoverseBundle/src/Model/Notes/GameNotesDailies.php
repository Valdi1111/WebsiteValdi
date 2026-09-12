<?php

namespace App\HoyoverseBundle\Model\Notes;

class GameNotesDailies
{
    private ?int $currentTask = null;

    private ?int $maxTask = null;

    public function getCurrentTask(): ?int
    {
        return $this->currentTask;
    }

    public function setCurrentTask(?int $currentTask): self
    {
        $this->currentTask = $currentTask;
        return $this;
    }

    public function getMaxTask(): ?int
    {
        return $this->maxTask;
    }

    public function setMaxTask(?int $maxTask): self
    {
        $this->maxTask = $maxTask;
        return $this;
    }

    public function isDone(): bool
    {
        return $this->getCurrentTask() == $this->getMaxTask();
    }

}