<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * Shiyu Defense period of the player (game_record_zzz hadal_info_v2, requested with the per-stage detail).
 *
 * Only the fifth frontier is scored: three stages, ShiyuDefenseStage per stage. The rules of the Endgames
 * Reminder (played, maximum) are the constants and methods below.
 */
class ShiyuDefenseData
{
    /** Response format read by this class ("hadal_ver"; "v1" is the old format, with other keys) */
    public const string SUPPORTED_VERSION = 'v2';

    /** Scored stages of the fifth frontier */
    public const int STAGE_COUNT = 3;

    /** Score from which a stage gets the S rating (each stage is worth up to 50,000 points) */
    public const int S_RANK_SCORE = 25000;

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

    /**
     * A missing version is read as the current format: the endpoint is hadal_info_v2.
     */
    public function isSupportedVersion(): bool
    {
        return $this->getVersion() === null || $this->getVersion() === self::SUPPORTED_VERSION;
    }

    /**
     * Played = at least one attempt with a score or a rating (so a mode at the maximum is never "not played").
     */
    public function isPlayed(): bool
    {
        if (($this->getScore() ?? 0) > 0) {
            return true;
        }

        foreach ($this->getStages() ?? [] as $stage) {
            if ($stage->isPlayed()) {
                return true;
            }
        }

        return false;
    }

    public function getSRankStageCount(): int
    {
        return count(array_filter($this->getStages() ?? [], static fn (ShiyuDefenseStage $stage) => $stage->isSRank()));
    }

    /**
     * Maximum = every stage at S rank.
     */
    public function isFullyCleared(): bool
    {
        return $this->getSRankStageCount() >= self::STAGE_COUNT;
    }
}
