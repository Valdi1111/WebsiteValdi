<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Endgame\GameEndgames;
use App\GamesBundle\Model\Endgame\GameEndgameStage;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\HoyolabException;
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
            self::withoutEmptyDates($body['data'] ?? [], [
                ['hadal_info_v2', 'hadal_begin_time'],
                ['hadal_info_v2', 'hadal_end_time'],
            ]),
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
            self::withoutEmptyDates($body['data'] ?? [], [['start_time'], ['end_time']]),
            DeadlyAssaultData::class
        );
    }

    /**
     * Every mode is read on its own: one that cannot be read (request refused, unexpected shape) is left out with a
     * warning and the others are still returned. Only when no mode could be read the error of the first one is
     * thrown, so an account-wide problem (expired cookie, authentication retcode) still fails the task like in the
     * other reminders.
     */
    public function getEndgamesData(RuntimeAccountDataInterface $account): GameEndgames
    {
        $endgames = new GameEndgames();
        $errors = [];
        $modes = [
            'Shiyu Defense' => fn (): array => $this->readShiyuDefenseEndgames($account),
            'Deadly Assault' => fn (): array => $this->readDeadlyAssaultEndgames($account),
        ];

        foreach ($modes as $name => $read) {
            try {
                foreach ($read() as $endgame) {
                    $endgames->addEndgame($endgame);
                }
            } catch (\Throwable $e) {
                $errors[$name] = $e;
                $profile = $account->getGameProfile();
                // Class and retcode only: the message and the response body can carry account data
                $this->getLogger()->warning(sprintf(
                    '%s could not be read, skipped by the endgames reminder: %s%s',
                    $name,
                    $e::class,
                    $e instanceof HoyolabException && $e->getHoyolabRetcode() !== null ? sprintf(' (retcode %d)', $e->getHoyolabRetcode()) : ''
                ), [
                    'uid' => $profile->getGameUid(),
                    'region' => $profile->getRegion(),
                ]);
            }
        }

        if (count($errors) === count($modes)) {
            throw reset($errors);
        }

        return $endgames;
    }

    /**
     * @return GameEndgame[]
     */
    private function readShiyuDefenseEndgames(RuntimeAccountDataInterface $account): array
    {
        $profile = $account->getGameProfile();
        $region = (string) $profile->getRegion();

        $shiyuDefense = $this->getShiyuDefense($account);
        if (!$shiyuDefense->isSupportedVersion()) {
            // Unknown format: played/maximum cannot be told, so no reminder rather than a wrong one
            $this->getLogger()->warning(sprintf(
                'Unknown Shiyu Defense response version "%s": skipped by the endgames reminder.',
                $shiyuDefense->getVersion()
            ), [
                'uid' => $profile->getGameUid(),
                'region' => $region,
            ]);

            return [];
        }

        return [$this->createShiyuDefenseEndgame($shiyuDefense, $region)];
    }

    /**
     * @return GameEndgame[] normal mode, then Hard mode
     */
    private function readDeadlyAssaultEndgames(RuntimeAccountDataInterface $account): array
    {
        $region = (string) $account->getGameProfile()->getRegion();

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

        $hard = new GameEndgame()
            ->setName('Deadly Assault (Hard)')
            ->setUnlocked($deadlyAssault->isHardUnlocked())
            ->setEndTime($deadlyAssaultEnd)
            ->setPlayed($deadlyAssault->isHardPlayed())
            ->setProgress($deadlyAssault->getHardStars())
            ->setMaxProgress(DeadlyAssaultData::HARD_MAX_STARS)
            ->setProgressUnit('★');
        $this->addDeadlyAssaultStages($hard, $deadlyAssault->getHardChallenges() ?? [], $deadlyAssault->getRoomMaxScore());

        return [$normal, $hard];
    }

    /**
     * Turns the empty date objects of the responses (all null, zeros, missing parts) into null: no date, instead of
     * a denormalization error or a date in year -1. Only the given paths are touched.
     *
     * @param array<string, mixed>|mixed $data
     * @param list<list<string>> $paths
     * @return array<string, mixed>
     */
    private static function withoutEmptyDates(mixed $data, array $paths): array
    {
        if (!is_array($data)) {
            return [];
        }

        foreach ($paths as $path) {
            $parent = &$data;
            foreach (array_slice($path, 0, -1) as $key) {
                if (!isset($parent[$key]) || !is_array($parent[$key])) {
                    unset($parent);
                    continue 2;
                }
                $parent = &$parent[$key];
            }

            $key = end($path);
            if (isset($parent[$key]) && is_array($parent[$key]) && self::isEmptyDate($parent[$key])) {
                $parent[$key] = null;
            }
            unset($parent);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $date {year, month, day, hour, minute, second} object
     */
    private static function isEmptyDate(array $date): bool
    {
        foreach (['year', 'month', 'day', 'hour', 'minute'] as $part) {
            if (!isset($date[$part]) || !is_numeric($date[$part])) {
                return true;
            }
        }

        return (int) $date['year'] <= 0;
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
