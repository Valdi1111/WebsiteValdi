<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Deadly Assault period of the player (game_record_zzz hadal_mem_detail_v2), normal mode and Hard mode.
 *
 * The rules of the Endgames Reminder (played, maximum, Hard unlocked) are the constants and methods below.
 */
class DeadlyAssaultData
{
    /** Stars of one boss at the maximum */
    public const int BOSS_MAX_STARS = 3;

    /** Normal mode at the maximum: three bosses with three stars each */
    public const int MAX_STARS = 9;

    /** Hard mode at the maximum: one boss with three stars */
    public const int HARD_MAX_STARS = 3;

    #[SerializedName('has_data')]
    private ?bool $hasData = null;

    #[SerializedName('zone_id')]
    private ?int $zoneId = null;

    /** Start of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedName('start_time')]
    private ?\DateTimeImmutable $startTime = null;

    /** End of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedName('end_time')]
    private ?\DateTimeImmutable $endTime = null;

    #[SerializedName('total_score')]
    private ?int $totalScore = null;

    #[SerializedName('total_star')]
    private ?int $totalStar = null;

    #[SerializedName('total_max_score')]
    private ?int $totalMaxScore = null;

    /** Maximum score of one boss */
    #[SerializedName('room_max_score')]
    private ?int $roomMaxScore = null;

    /**
     * @var DeadlyAssaultChallenge[]|null
     */
    #[SerializedName('list')]
    private ?array $challenges = null;

    #[SerializedName('has_hard')]
    private ?bool $hasHard = null;

    /**
     * @var DeadlyAssaultChallenge[]|null
     */
    #[SerializedName('hard_list')]
    private ?array $hardChallenges = null;

    public function getHasData(): ?bool
    {
        return $this->hasData;
    }

    public function setHasData(?bool $hasData): static
    {
        $this->hasData = $hasData;
        return $this;
    }

    public function getZoneId(): ?int
    {
        return $this->zoneId;
    }

    public function setZoneId(?int $zoneId): static
    {
        $this->zoneId = $zoneId;
        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function getTotalScore(): ?int
    {
        return $this->totalScore;
    }

    public function setTotalScore(?int $totalScore): static
    {
        $this->totalScore = $totalScore;
        return $this;
    }

    public function getTotalStar(): ?int
    {
        return $this->totalStar;
    }

    public function setTotalStar(?int $totalStar): static
    {
        $this->totalStar = $totalStar;
        return $this;
    }

    public function getTotalMaxScore(): ?int
    {
        return $this->totalMaxScore;
    }

    public function setTotalMaxScore(?int $totalMaxScore): static
    {
        $this->totalMaxScore = $totalMaxScore;
        return $this;
    }

    public function getRoomMaxScore(): ?int
    {
        return $this->roomMaxScore;
    }

    public function setRoomMaxScore(?int $roomMaxScore): static
    {
        $this->roomMaxScore = $roomMaxScore;
        return $this;
    }

    /**
     * @return DeadlyAssaultChallenge[]|null
     */
    public function getChallenges(): ?array
    {
        return $this->challenges;
    }

    /**
     * @param DeadlyAssaultChallenge[]|null $challenges
     */
    public function setChallenges(?array $challenges): static
    {
        $this->challenges = $challenges;
        return $this;
    }

    public function getHasHard(): ?bool
    {
        return $this->hasHard;
    }

    public function setHasHard(?bool $hasHard): static
    {
        $this->hasHard = $hasHard;
        return $this;
    }

    /**
     * @return DeadlyAssaultChallenge[]|null
     */
    public function getHardChallenges(): ?array
    {
        return $this->hardChallenges;
    }

    /**
     * @param DeadlyAssaultChallenge[]|null $hardChallenges
     */
    public function setHardChallenges(?array $hardChallenges): static
    {
        $this->hardChallenges = $hardChallenges;
        return $this;
    }

    /**
     * Played = at least one attempt with a score.
     */
    public function isPlayed(): bool
    {
        if ($this->getHasData() === false) {
            return false;
        }

        return ($this->getTotalScore() ?? 0) > 0 || self::anyPlayed($this->getChallenges());
    }

    /**
     * Stars of the normal mode: the sum over the bosses, or total_star when the list is missing.
     */
    public function getStars(): int
    {
        if ($this->getHasData() === false) {
            return 0;
        }

        $challenges = $this->getChallenges() ?? [];
        if ($challenges === []) {
            return $this->getTotalStar() ?? 0;
        }

        return self::sumStars($challenges);
    }

    /**
     * Maximum = nine stars.
     */
    public function isFullyCleared(): bool
    {
        return $this->getStars() >= self::MAX_STARS;
    }

    /**
     * Hard mode opens only with the normal mode at the maximum. has_hard is not used: what it means for a
     * player who has not tried Hard mode yet is unknown.
     */
    public function isHardUnlocked(): bool
    {
        return $this->isFullyCleared();
    }

    public function isHardPlayed(): bool
    {
        return $this->getHasData() !== false && self::anyPlayed($this->getHardChallenges());
    }

    public function getHardStars(): int
    {
        if ($this->getHasData() === false) {
            return 0;
        }

        return self::sumStars($this->getHardChallenges() ?? []);
    }

    /**
     * Hard mode at the maximum = three stars.
     */
    public function isHardFullyCleared(): bool
    {
        return $this->getHardStars() >= self::HARD_MAX_STARS;
    }

    /**
     * @param DeadlyAssaultChallenge[]|null $challenges
     */
    private static function anyPlayed(?array $challenges): bool
    {
        foreach ($challenges ?? [] as $challenge) {
            if ($challenge->isPlayed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param DeadlyAssaultChallenge[] $challenges
     */
    private static function sumStars(array $challenges): int
    {
        return array_sum(array_map(static fn (DeadlyAssaultChallenge $challenge) => $challenge->getStar() ?? 0, $challenges));
    }
}
