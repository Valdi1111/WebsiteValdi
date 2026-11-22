<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Endgame\GameEndgames;
use App\GamesBundle\Model\Endgame\GameEndgameStage;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\RetrieveDeadlyAssaultDataException;
use App\HoyoverseBundle\Exception\RetrieveShiyuDefenseDataException;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultChallenge;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultData;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseData;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait ZenlessZoneZeroEndgamesTrait
{
    private ?HoyoverseServerTimezoneResolver $serverTimezoneResolver = null;

    public function getServerTimezoneResolver(): ?HoyoverseServerTimezoneResolver
    {
        return $this->serverTimezoneResolver;
    }

    #[Required]
    public function setServerTimezoneResolver(HoyoverseServerTimezoneResolver $serverTimezoneResolver): void
    {
        $this->serverTimezoneResolver = $serverTimezoneResolver;
    }

    public function getUrlShiyuDefense(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_info_v2";
    }

    /**
     * Shiyu Defense of the current period, with the score of every stage.
     */
    public function getShiyuDefense(RuntimeAccountDataInterface $account): ShiyuDefenseData
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlShiyuDefense(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
            ],
            exceptionClass: RetrieveShiyuDefenseDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            ShiyuDefenseData::class
        );
    }

    public function getUrlDeadlyAssault(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_mem_detail_v2";
    }

    /**
     * Deadly Assault of the current period, with the score and stars of every boss (normal and Hard mode).
     */
    public function getDeadlyAssault(RuntimeAccountDataInterface $account): DeadlyAssaultData
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlDeadlyAssault(),
            auth: $account,
            query: [
                'uid' => $account->getGameProfile()->getGameUid(),
                'region' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
            ],
            exceptionClass: RetrieveDeadlyAssaultDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            DeadlyAssaultData::class
        );
    }

    public function getEndgamesData(RuntimeAccountDataInterface $account): GameEndgames
    {
        $profile = $account->getGameProfile();
        $region = (string) $profile->getRegion();
        $endgames = new GameEndgames();

        $shiyuDefense = $this->getShiyuDefense($account);
        if ($shiyuDefense->isSupportedVersion()) {
            $endgames->addEndgame($this->createShiyuDefenseEndgame($shiyuDefense, $region));
        } else {
            // Unknown format: played/maximum cannot be told, so no reminder rather than a wrong one
            $this->getLogger()->warning(sprintf(
                'Unknown Shiyu Defense response version "%s": skipped by the endgames reminder.',
                $shiyuDefense->getVersion()
            ), [
                'uid' => $profile->getGameUid(),
                'region' => $region,
            ]);
        }

        $deadlyAssault = $this->getDeadlyAssault($account);
        $deadlyAssaultEnd = $this->toServerInstant($deadlyAssault->getEndTime(), $region);

        $normal = new GameEndgame()
            ->setName('Deadly Assault')
            ->setEndTime($deadlyAssaultEnd)
            ->setPlayed($deadlyAssault->isPlayed())
            ->setProgress($deadlyAssault->getStars())
            ->setMaxProgress(DeadlyAssaultData::MAX_STARS)
            ->setProgressUnit('★');
        $this->addDeadlyAssaultStages($normal, $deadlyAssault->getChallenges() ?? [], $deadlyAssault->getRoomMaxScore());
        $endgames->addEndgame($normal);

        $hard = new GameEndgame()
            ->setName('Deadly Assault (Hard)')
            ->setUnlocked($deadlyAssault->isHardUnlocked())
            ->setEndTime($deadlyAssaultEnd)
            ->setPlayed($deadlyAssault->isHardPlayed())
            ->setProgress($deadlyAssault->getHardStars())
            ->setMaxProgress(DeadlyAssaultData::HARD_MAX_STARS)
            ->setProgressUnit('★');
        $this->addDeadlyAssaultStages($hard, $deadlyAssault->getHardChallenges() ?? [], $deadlyAssault->getRoomMaxScore());
        $endgames->addEndgame($hard);

        return $endgames;
    }

    private function createShiyuDefenseEndgame(ShiyuDefenseData $shiyuDefense, string $region): GameEndgame
    {
        $endgame = new GameEndgame()
            ->setName('Shiyu Defense')
            ->setEndTime($this->getShiyuDefenseEndTime($shiyuDefense, $region))
            ->setPlayed($shiyuDefense->isPlayed())
            ->setProgress($shiyuDefense->getSRankStageCount())
            ->setMaxProgress(ShiyuDefenseData::STAGE_COUNT)
            ->setProgressUnit('stages at S rank');

        foreach (array_values($shiyuDefense->getStages() ?? []) as $index => $stage) {
            $endgame->addStage(
                new GameEndgameStage()
                    ->setName(sprintf('Stage %d', $index + 1))
                    ->setScore($stage->getScore())
                    ->setMaxScore($stage->getMaxScore())
                    ->setResult($stage->getRating())
                    ->setMaxed($stage->isSRank())
            );
        }

        return $endgame;
    }

    /**
     * @param DeadlyAssaultChallenge[] $challenges
     */
    private function addDeadlyAssaultStages(GameEndgame $endgame, array $challenges, ?int $maxScore): void
    {
        foreach (array_values($challenges) as $index => $challenge) {
            $endgame->addStage(
                new GameEndgameStage()
                    ->setName($challenge->getBossName() ?? sprintf('Boss %d', $index + 1))
                    ->setScore($challenge->getScore())
                    ->setMaxScore($maxScore)
                    ->setResult(sprintf('%d★', $challenge->getStar() ?? 0))
                    ->setMaxed($challenge->isFullyCleared())
            );
        }
    }

    /**
     * The epoch end time is preferred (unambiguous); the server wall-clock object is the fallback.
     */
    private function getShiyuDefenseEndTime(ShiyuDefenseData $shiyuDefense, string $region): ?\DateTimeImmutable
    {
        $timestamp = $shiyuDefense->getEndTimestamp();
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return new \DateTimeImmutable('@' . (int) $timestamp)
                ->setTimezone($this->getServerTimezoneResolver()->getServerTimezoneForRegion($region));
        }

        return $this->toServerInstant($shiyuDefense->getEndTime(), $region);
    }

    private function toServerInstant(?\DateTimeInterface $wallClock, string $region): ?\DateTimeImmutable
    {
        if ($wallClock === null) {
            return null;
        }

        return $this->getServerTimezoneResolver()->serverWallClockToInstant($wallClock, $region);
    }
}
