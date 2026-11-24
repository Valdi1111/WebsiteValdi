<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One period of Stygian Onslaught (hard_challenge data[]), with the player's solo and co-op records.
 *
 * The rules of the Endgames Reminder (played, difficulty completed) are the constants and methods below.
 */
class StygianOnslaughtType
{
    /**
     * Difficulties by difficulty id, easiest first: level key and name in the profile settings. Dire opens once
     * Fearless is completed; its id (6) is not verified on data yet.
     *
     * @var array<int, array{key: string, name: string}>
     */
    public const array DIFFICULTIES = [
        1 => ['key' => 'normal', 'name' => 'Normal'],
        2 => ['key' => 'advancing', 'name' => 'Advancing'],
        3 => ['key' => 'hard', 'name' => 'Hard'],
        4 => ['key' => 'menacing', 'name' => 'Menacing'],
        5 => ['key' => 'fearless', 'name' => 'Fearless'],
        6 => ['key' => 'dire', 'name' => 'Dire'],
    ];

    #[SerializedName('schedule')]
    private ?ScheduleData $schedule = null;

    /** Solo record */
    #[SerializedName('single')]
    private ?StygianOnslaughtRecord $single = null;

    /** Co-op record */
    #[SerializedName('mp')]
    private ?StygianOnslaughtRecord $mp = null;

    public function getSchedule(): ?ScheduleData
    {
        return $this->schedule;
    }

    public function setSchedule(?ScheduleData $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
    }

    public function getSingle(): ?StygianOnslaughtRecord
    {
        return $this->single;
    }

    public function setSingle(?StygianOnslaughtRecord $single): static
    {
        $this->single = $single;
        return $this;
    }

    public function getMp(): ?StygianOnslaughtRecord
    {
        return $this->mp;
    }

    public function setMp(?StygianOnslaughtRecord $mp): static
    {
        $this->mp = $mp;
        return $this;
    }

    /**
     * Played = a record solo or in co-op (has_data), with or without a best: an attempt lost before any win counts.
     */
    public function isPlayed(): bool
    {
        return $this->getSingle()?->getHasData() === true || $this->getMp()?->getHasData() === true;
    }

    /**
     * The best of the solo and co-op bests: the higher difficulty, then the faster run. Null without a best.
     */
    public function getBest(): ?StygianOnslaughtBest
    {
        $best = null;
        foreach ([$this->getSingle()?->getBest(), $this->getMp()?->getBest()] as $candidate) {
            if ($candidate === null || $candidate->getDifficulty() === null) {
                continue;
            }

            if ($best === null
                || $candidate->getDifficulty() > $best->getDifficulty()
                || ($candidate->getDifficulty() === $best->getDifficulty() && ($candidate->getSecond() ?? PHP_INT_MAX) < ($best->getSecond() ?? PHP_INT_MAX))
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * A best whose difficulty is above the known ones (a tier added after Dire): the completed level cannot be told.
     */
    public function hasUnknownDifficulty(): bool
    {
        return ($this->getBest()?->getDifficulty() ?? 0) > array_key_last(self::DIFFICULTIES);
    }

    /**
     * The highest difficulty completed: that of the best, solo or co-op. Null without a best or when unknown.
     *
     * Assumes that a best is a win (a lost attempt leaves none): to be checked in the next period.
     */
    public function getCompletedDifficulty(): ?int
    {
        $difficulty = $this->getBest()?->getDifficulty();

        return $difficulty !== null && isset(self::DIFFICULTIES[$difficulty]) ? $difficulty : null;
    }
}
