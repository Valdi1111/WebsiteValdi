<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * Shiyu Defense period of the player (game_record_zzz hadal_info_v2, requested with the per-stage detail).
 *
 * The first three floors are completed automatically; the fourth floor is rated without a score, the fifth floor
 * (fifth frontier) has three scored stages, ShiyuDefenseStage per stage. The rules of the Endgames Reminder
 * (played, maximum) are the constants and methods below.
 */
class ShiyuDefenseData
{
    /** Response format read by this class ("hadal_ver"; "v1" is the old format, with other keys) */
    public const string SUPPORTED_VERSION = 'v2';

    /** Scored stages of the fifth floor */
    public const int STAGE_COUNT = 3;

    /** Rating of the fourth floor ("S"), without a score */
    #[SerializedPath('[hadal_info_v2?][fourth_layer_detail?][rating]')]
    private ?string $fourthFloorRating = null;

    /**
     * Teams sent to the fourth floor, only counted to tell whether it was attempted.
     *
     * @var list<array<string, mixed>>|null
     */
    #[SerializedPath('[hadal_info_v2?][fourth_layer_detail?][layer_challenge_info_list]')]
    private ?array $fourthFloorChallenges = null;

    #[SerializedName('hadal_ver')]
    private ?string $version = null;

    #[SerializedPath('[hadal_info_v2?][zone_id]')]
    private ?int $zoneId = null;

    /** Start of the period, epoch seconds (a string in the responses) */
    #[SerializedPath('[hadal_info_v2?][begin_time]')]
    private int|string|null $beginTimestamp = null;

    /** End of the period, epoch seconds (a string in the responses) */
    #[SerializedPath('[hadal_info_v2?][end_time]')]
    private int|string|null $endTimestamp = null;

    /** Start of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedPath('[hadal_info_v2?][hadal_begin_time]')]
    private ?\DateTimeImmutable $beginTime = null;

    /** End of the period, server wall-clock time (see HoyoverseServerTimezoneResolver::serverWallClockToInstant()) */
    #[SerializedPath('[hadal_info_v2?][hadal_end_time]')]
    private ?\DateTimeImmutable $endTime = null;

    #[SerializedPath('[hadal_info_v2?][pass_fifth_floor]')]
    private ?bool $passFifthFloor = null;

    /** Total score of the fifth frontier */
    #[SerializedPath('[hadal_info_v2?][brief?][score]')]
    private ?int $score = null;

    #[SerializedPath('[hadal_info_v2?][brief?][max_score]')]
    private ?int $maxScore = null;

    /** Overall rating, e.g. "S+" */
    #[SerializedPath('[hadal_info_v2?][brief?][rating]')]
    private ?string $rating = null;

    /**
     * @var ShiyuDefenseStage[]|null
     */
    #[SerializedPath('[hadal_info_v2?][fitfh_layer_detail?][layer_challenge_info_list]')]
    private ?array $stages = null;

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;
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

    public function getBeginTimestamp(): int|string|null
    {
        return $this->beginTimestamp;
    }

    public function setBeginTimestamp(int|string|null $beginTimestamp): static
    {
        $this->beginTimestamp = $beginTimestamp;
        return $this;
    }

    public function getEndTimestamp(): int|string|null
    {
        return $this->endTimestamp;
    }

    public function setEndTimestamp(int|string|null $endTimestamp): static
    {
        $this->endTimestamp = $endTimestamp;
        return $this;
    }

    public function getBeginTime(): ?\DateTimeImmutable
    {
        return $this->beginTime;
    }

    public function setBeginTime(?\DateTimeImmutable $beginTime): static
    {
        $this->beginTime = $beginTime;
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

    public function getPassFifthFloor(): ?bool
    {
        return $this->passFifthFloor;
    }

    public function setPassFifthFloor(?bool $passFifthFloor): static
    {
        $this->passFifthFloor = $passFifthFloor;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getMaxScore(): ?int
    {
        return $this->maxScore;
    }

    public function setMaxScore(?int $maxScore): static
    {
        $this->maxScore = $maxScore;
        return $this;
    }

    public function getRating(): ?string
    {
        return $this->rating;
    }

    public function setRating(?string $rating): static
    {
        $this->rating = $rating;
        return $this;
    }

    /**
     * @return ShiyuDefenseStage[]|null
     */
    public function getStages(): ?array
    {
        return $this->stages;
    }

    /**
     * @param ShiyuDefenseStage[]|null $stages
     */
    public function setStages(?array $stages): static
    {
        $this->stages = $stages;
        return $this;
    }

    public function getFourthFloorRating(): ?string
    {
        return $this->fourthFloorRating;
    }

    public function setFourthFloorRating(?string $fourthFloorRating): static
    {
        $this->fourthFloorRating = $fourthFloorRating;
        return $this;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getFourthFloorChallenges(): ?array
    {
        return $this->fourthFloorChallenges;
    }

    /**
     * @param list<array<string, mixed>>|null $fourthFloorChallenges
     */
    public function setFourthFloorChallenges(?array $fourthFloorChallenges): static
    {
        $this->fourthFloorChallenges = $fourthFloorChallenges;
        return $this;
    }

    /**
     * S rank as rated by HoYoLAB. A prefix match, so that a variant such as "S+" (seen on the overall rating) counts.
     */
    public static function isSRating(?string $rating): bool
    {
        return $rating !== null && str_starts_with($rating, 'S');
    }

    /**
     * A missing version is read as the current format: the endpoint is hadal_info_v2.
     */
    public function isSupportedVersion(): bool
    {
        return $this->getVersion() === null || $this->getVersion() === self::SUPPORTED_VERSION;
    }

    public function isFourthFloorPlayed(): bool
    {
        return ($this->getFourthFloorRating() ?? '') !== '' || ($this->getFourthFloorChallenges() ?? []) !== [];
    }

    public function isFourthFloorSRank(): bool
    {
        return self::isSRating($this->getFourthFloorRating());
    }

    public function isFifthFloorPlayed(): bool
    {
        foreach ($this->getStages() ?? [] as $stage) {
            if ($stage->isPlayed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Played = the fourth floor attempted, or a score or a rating on the fifth floor (so a mode at the maximum is
     * never "not played").
     */
    public function isPlayed(): bool
    {
        return ($this->getScore() ?? 0) > 0 || $this->isFourthFloorPlayed() || $this->isFifthFloorPlayed();
    }

    public function getSRankStageCount(): int
    {
        return count(array_filter($this->getStages() ?? [], static fn (ShiyuDefenseStage $stage) => $stage->isSRank()));
    }

    /**
     * Maximum = the fourth floor and every stage of the fifth floor at S rank.
     */
    public function isFullyCleared(): bool
    {
        return $this->isFourthFloorSRank() && $this->getSRankStageCount() >= self::STAGE_COUNT;
    }
}
