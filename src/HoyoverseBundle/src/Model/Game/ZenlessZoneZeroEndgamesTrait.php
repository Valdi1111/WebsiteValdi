<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\RetrieveDeadlyAssaultDataException;
use App\HoyoverseBundle\Exception\RetrieveShiyuDefenseDataException;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultData;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseData;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait ZenlessZoneZeroEndgamesTrait
{
    use CachedEndgameRequestTrait;
    use HoyolabEndgameDatesTrait;

    public const string ENDGAMES_CONFIG_KEY = 'zzz';

    public const string ENDGAME_SHIYU_DEFENSE = 'shiyu_defense';

    public const string ENDGAME_DEADLY_ASSAULT = 'deadly_assault';

    /** Profile setting of the Deadly Assault Hard mode, next to the "reminder" of the normal mode */
    public const string ENDGAME_VARIANT_HARD = 'hard_reminder';

    final public static function getEndgamesConfigKey(): string
    {
        return self::ENDGAMES_CONFIG_KEY;
    }

    /**
     * Shiyu Defense, then Deadly Assault with its Hard mode (optional content: no reminder unless the player asks).
     */
    final public static function getEndgameDefinitions(): array
    {
        return [
            new EndgameDefinition(self::ENDGAME_SHIYU_DEFENSE, 'Shiyu Defense'),
            new EndgameDefinition(self::ENDGAME_DEADLY_ASSAULT, 'Deadly Assault', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Deadly Assault'),
                new EndgameVariant(self::ENDGAME_VARIANT_HARD, 'Deadly Assault (Hard)', EndgameReminder::Off),
            ]),
        ];
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account, bool $fresh = false): array
    {
        // One response per mode, whose fetch time every result of the mode carries
        $entry = $this->requestEndgameMode($definition->key, $account, $fresh);

        return $this->withEndgameFetchTime(match ($definition->key) {
            self::ENDGAME_SHIYU_DEFENSE => $this->readShiyuDefenseEndgames($definition, $account, $this->denormalizeShiyuDefense($entry['data'])),
            self::ENDGAME_DEADLY_ASSAULT => $this->readDeadlyAssaultEndgames($definition, $account, $this->denormalizeDeadlyAssault($entry['data'])),
        }, $entry);
    }

    public function getUrlShiyuDefense(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_info_v2";
    }

    /**
     * Shiyu Defense of the current period, with the score of every stage.
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getShiyuDefense(RuntimeAccountDataInterface $account, bool $fresh = false): ShiyuDefenseData
    {
        return $this->denormalizeShiyuDefense($this->requestEndgameMode(self::ENDGAME_SHIYU_DEFENSE, $account, $fresh)['data']);
    }

    public function getUrlDeadlyAssault(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_mem_detail_v2";
    }

    /**
     * Deadly Assault of the current period, with the score and stars of every boss (normal and Hard mode).
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getDeadlyAssault(RuntimeAccountDataInterface $account, bool $fresh = false): DeadlyAssaultData
    {
        return $this->denormalizeDeadlyAssault($this->requestEndgameMode(self::ENDGAME_DEADLY_ASSAULT, $account, $fresh)['data']);
    }

    /**
     * The battle record request of a mode (current period: schedule_type 1), through the endgames cache.
     *
     * @return array{fetchedAt: int, data: array<string, mixed>}
     */
    private function requestEndgameMode(string $mode, RuntimeAccountDataInterface $account, bool $fresh): array
    {
        $profile = $account->getGameProfile();

        [$url, $query, $exceptionClass] = match ($mode) {
            self::ENDGAME_SHIYU_DEFENSE => [$this->getUrlShiyuDefense(), [
                'role_id' => $profile->getGameUid(),
                'server' => $profile->getRegion(),
                'schedule_type' => 1,
            ], RetrieveShiyuDefenseDataException::class],
            // Deadly Assault names the player parameters differently
            self::ENDGAME_DEADLY_ASSAULT => [$this->getUrlDeadlyAssault(), [
                'uid' => $profile->getGameUid(),
                'region' => $profile->getRegion(),
                'schedule_type' => 1,
            ], RetrieveDeadlyAssaultDataException::class],
        };

        return $this->requestCachedEndgame($account, $url, $query, $exceptionClass, $fresh);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function denormalizeShiyuDefense(array $data): ShiyuDefenseData
    {
        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyDates($data, [
                ['hadal_info_v2', 'hadal_begin_time'],
                ['hadal_info_v2', 'hadal_end_time'],
            ]),
            ShiyuDefenseData::class
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function denormalizeDeadlyAssault(array $data): DeadlyAssaultData
    {
        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyDates($data, [['start_time'], ['end_time']]),
            DeadlyAssaultData::class
        );
    }

    /**
     * @return list<GameEndgame>
     */
    private function readShiyuDefenseEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account, ShiyuDefenseData $shiyuDefense): array
    {
        $profile = $account->getGameProfile();
        $region = (string) $profile->getRegion();

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

        if ($shiyuDefense->isPlayed() && !$shiyuDefense->isFourthFloorPlayed() && !$shiyuDefense->isFifthFloorPlayed()) {
            // A score without the floor detail (HoYoLAB updates the totals first): played, but how far from the
            // maximum cannot be told yet, so no reminder in this run rather than a wrong one
            $this->getLogger()->info('Shiyu Defense score without the floor detail: skipped by the endgames reminder.', [
                'uid' => $profile->getGameUid(),
                'region' => $region,
            ]);

            return [];
        }

        $endgame = new GameEndgame()
            ->setName($definition->name)
            ->setEndTime($this->getShiyuDefenseEndTime($shiyuDefense, $region))
            ->setPlayed($shiyuDefense->isPlayed())
            ->setMaxed($shiyuDefense->isFullyCleared());

        // One line per floor, with its own icon; the stages below S rank under floor 5
        $endgame->addProgressLine(sprintf(
            'Floor 4: %s',
            $shiyuDefense->isFourthFloorPlayed() ? ($shiyuDefense->getFourthFloorRating() ?: 'played') : 'not played'
        ), '⚔️');

        if (!$shiyuDefense->isFifthFloorPlayed()) {
            return [$endgame->addProgressLine('Floor 5: not played', '⚔️')];
        }

        $endgame->addProgressLine(sprintf('Floor 5: %d/%d S', $shiyuDefense->getSRankStageCount(), ShiyuDefenseData::STAGE_COUNT), '⚔️');
        foreach (array_values($shiyuDefense->getStages() ?? []) as $index => $stage) {
            if ($stage->isSRank()) {
                continue;
            }

            $endgame->addProgressLine(sprintf(
                '↳ Stage %d: %s',
                $index + 1,
                $stage->isPlayed() ? trim(sprintf('%s (%s)', $stage->getRating() ?? '', number_format($stage->getScore() ?? 0))) : 'not played'
            ));
        }

        return [$endgame];
    }

    /**
     * @return list<GameEndgame> normal mode, then Hard mode
     */
    private function readDeadlyAssaultEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account, DeadlyAssaultData $deadlyAssault): array
    {
        $region = (string) $account->getGameProfile()->getRegion();

        $deadlyAssaultEnd = $this->toServerInstant($deadlyAssault->getEndTime(), $region);

        $normal = new GameEndgame()
            ->setName($definition->getVariant(EndgameVariant::DEFAULT_KEY)?->name ?? $definition->name)
            ->setVariant(EndgameVariant::DEFAULT_KEY)
            ->setEndTime($deadlyAssaultEnd)
            ->setPlayed($deadlyAssault->isPlayed())
            ->setMaxed($deadlyAssault->isFullyCleared())
            ->addProgressLine(sprintf('%d/%d ★', $deadlyAssault->getStars(), DeadlyAssaultData::MAX_STARS));

        $hard = new GameEndgame()
            ->setName($definition->getVariant(self::ENDGAME_VARIANT_HARD)?->name ?? $definition->name)
            ->setVariant(self::ENDGAME_VARIANT_HARD)
            ->setUnlocked($deadlyAssault->isHardUnlocked())
            ->setEndTime($deadlyAssaultEnd)
            ->setPlayed($deadlyAssault->isHardPlayed())
            ->setMaxed($deadlyAssault->isHardFullyCleared())
            ->addProgressLine(sprintf('%d/%d ★', $deadlyAssault->getHardStars(), DeadlyAssaultData::HARD_MAX_STARS));

        return [$normal, $hard];
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
}
