<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Result of the player's Imaginarium Theater run in a period (role_combat data[].stat), present also with
 * need_detail=false. All zeros when the period has not been played.
 *
 * There is one record per period, the last run replacing the previous one, at the difficulty the player picked. The
 * rules of the Endgames Reminder (difficulty completed, maximum) are the constants and methods below.
 */
class TheaterStat
{
    /**
     * Difficulties by difficulty_id, easiest first: level key and name in the profile settings, acts of a full run.
     *
     * @var array<int, array{key: string, name: string, acts: int}>
     */
    public const array DIFFICULTIES = [
        1 => ['key' => 'easy', 'name' => 'Easy', 'acts' => 3],
        2 => ['key' => 'normal', 'name' => 'Normal', 'acts' => 6],
        3 => ['key' => 'hard', 'name' => 'Hard', 'acts' => 8],
        4 => ['key' => 'visionary', 'name' => 'Visionary', 'acts' => 10],
        5 => ['key' => 'lunar', 'name' => 'Lunar', 'acts' => 10],
    ];

    /** Lunar is completed with its ten acts and both Arcana */
    public const int LUNAR_DIFFICULTY = 5;

    public const int LUNAR_ARCANA = 2;

    #[SerializedName('difficulty_id')]
    private ?int $difficultyId = null;

    /** Highest act of the run */
    #[SerializedName('max_round_id')]
    private ?int $maxRoundId = null;

    /** Arcana completed, 0 to 2 (Lunar only) */
    #[SerializedName('tarot_finished_cnt')]
    private ?int $tarotFinishedCount = null;

    /** Stars of the run: one per act, then one per Arcana; they decide no reward */
    #[SerializedName('medal_num')]
    private ?int $medalNum = null;

    /**
     * @var int[]|null one entry per act, then one per Arcana: 1 = star obtained
     */
    #[SerializedName('get_medal_round_list')]
    private ?array $medalRoundList = null;

    public function getDifficultyId(): ?int
    {
        return $this->difficultyId;
    }

    public function setDifficultyId(?int $difficultyId): static
    {
        $this->difficultyId = $difficultyId;
        return $this;
    }

    public function getMaxRoundId(): ?int
    {
        return $this->maxRoundId;
    }

    public function setMaxRoundId(?int $maxRoundId): static
    {
        $this->maxRoundId = $maxRoundId;
        return $this;
    }

    public function getTarotFinishedCount(): ?int
    {
        return $this->tarotFinishedCount;
    }

    public function setTarotFinishedCount(?int $tarotFinishedCount): static
    {
        $this->tarotFinishedCount = $tarotFinishedCount;
        return $this;
    }

    public function getMedalNum(): ?int
    {
        return $this->medalNum;
    }

    public function setMedalNum(?int $medalNum): static
    {
        $this->medalNum = $medalNum;
        return $this;
    }

    /**
     * @return int[]|null
     */
    public function getMedalRoundList(): ?array
    {
        return $this->medalRoundList;
    }

    /**
     * @param int[]|null $medalRoundList
     */
    public function setMedalRoundList(?array $medalRoundList): static
    {
        $this->medalRoundList = $medalRoundList;
        return $this;
    }

    /**
     * A difficulty_id above the known ones (a tier added after Lunar): the completed level cannot be told.
     */
    public function hasUnknownDifficulty(): bool
    {
        return ($this->getDifficultyId() ?? 0) > array_key_last(self::DIFFICULTIES);
    }

    /**
     * @return array{key: string, name: string, acts: int}|null the difficulty of the run, null when not played or unknown
     */
    public function getDifficulty(): ?array
    {
        return self::DIFFICULTIES[$this->getDifficultyId() ?? 0] ?? null;
    }

    /**
     * The highest difficulty completed: the highest L with difficulty_id ≥ L and max_round_id ≥ the acts of L, Lunar
     * also needing both Arcana. A harder run counts for the easier difficulties up to the act reached: Lunar up to
     * act 10 without both Arcana = Visionary, Lunar up to act 8 = Hard. Null when nothing is completed or the
     * difficulty is unknown.
     *
     * Assumes that max_round_id is the last act completed (an act reached and lost not counted): to be checked on a
     * run that stopped early (December 2026 period).
     */
    public function getCompletedDifficulty(): ?int
    {
        if ($this->hasUnknownDifficulty()) {
            return null;
        }

        $difficultyId = $this->getDifficultyId() ?? 0;
        $acts = $this->getMaxRoundId() ?? 0;
        foreach (array_reverse(self::DIFFICULTIES, true) as $id => $difficulty) {
            if ($id > $difficultyId || $acts < $difficulty['acts']) {
                continue;
            }

            if ($id === self::LUNAR_DIFFICULTY && ($this->getTarotFinishedCount() ?? 0) < self::LUNAR_ARCANA) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /**
     * Stars available at the difficulty of the run: one per act, plus one per Arcana in Lunar (e.g. 12 for Lunar).
     */
    public function getMaxMedals(): int
    {
        $difficulty = $this->getDifficulty();
        if ($difficulty === null) {
            return 0;
        }

        return $difficulty['acts'] + ($this->getDifficultyId() === self::LUNAR_DIFFICULTY ? self::LUNAR_ARCANA : 0);
    }
}
