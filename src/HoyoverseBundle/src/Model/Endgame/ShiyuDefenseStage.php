<?php

namespace App\HoyoverseBundle\Model\Endgame;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One scored stage of the Shiyu Defense fifth frontier (fitfh_layer_detail.layer_challenge_info_list[]).
 */
class ShiyuDefenseStage
{
    #[SerializedName('layer_id')]
    private ?int $layerId = null;

    #[SerializedName('rating')]
    private ?string $rating = null;

    #[SerializedName('score')]
    private ?int $score = null;

    #[SerializedName('max_score')]
    private ?int $maxScore = null;

    public function getLayerId(): ?int
    {
        return $this->layerId;
    }

    public function setLayerId(?int $layerId): static
    {
        $this->layerId = $layerId;
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

    /**
     * Played = a score or a rating (a stage at S rank has been played, whatever its score says).
     */
    public function isPlayed(): bool
    {
        return ($this->getScore() ?? 0) > 0 || ($this->getRating() ?? '') !== '';
    }

    /**
     * S rank as rated by HoYoLAB: the score is not compared with any threshold.
     */
    public function isSRank(): bool
    {
        return ShiyuDefenseData::isSRating($this->getRating());
    }
}
