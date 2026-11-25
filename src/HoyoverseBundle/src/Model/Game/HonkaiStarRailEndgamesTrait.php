<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\RetrieveAnomalyArbitrationDataException;
use App\HoyoverseBundle\Exception\RetrieveApocalypticShadowDataException;
use App\HoyoverseBundle\Exception\RetrieveMemoryOfChaosDataException;
use App\HoyoverseBundle\Exception\RetrievePureFictionDataException;
use App\HoyoverseBundle\Model\Endgame\AnomalyArbitrationData;
use App\HoyoverseBundle\Model\Endgame\AnomalyArbitrationRecord;
use App\HoyoverseBundle\Model\Endgame\HonkaiStarRailChallengeData;
use App\HoyoverseBundle\Model\Endgame\HonkaiStarRailChallengeFloor;
use App\HoyoverseBundle\Model\Endgame\HonkaiStarRailEndgamePeriod;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait HonkaiStarRailEndgamesTrait
{
    use CachedEndgameRequestTrait;
    use HoyolabEndgameDatesTrait;

    public const string ENDGAMES_CONFIG_KEY = 'hsr';

    public const string ENDGAME_MEMORY_OF_CHAOS = 'memory_of_chaos';

    public const string ENDGAME_PURE_FICTION = 'pure_fiction';

    public const string ENDGAME_APOCALYPTIC_SHADOW = 'apocalyptic_shadow';

    public const string ENDGAME_ANOMALY_ARBITRATION = 'anomaly_arbitration';

    /** Profile setting of Starward Mode, next to the "reminder" of the normal part (Memory of Chaos, Pure Fiction, Apocalyptic Shadow) */
    public const string ENDGAME_VARIANT_STARWARD = 'starward_reminder';

    /** Profile setting of Plight, next to the "reminder" of the normal part (Anomaly Arbitration) */
    public const string ENDGAME_VARIANT_PLIGHT = 'hard_reminder';

    private const string ENDGAMES_BASE_URL = 'https://bbs-api-os.hoyolab.com/game_record/hkrpg/api/';

    private ?ClockInterface $endgamesClock = null;

    public function getEndgamesClock(): ?ClockInterface
    {
        return $this->endgamesClock;
    }

    #[Required]
    public function setEndgamesClock(ClockInterface $clock): void
    {
        $this->endgamesClock = $clock;
    }

    final public static function getEndgamesConfigKey(): string
    {
        return self::ENDGAMES_CONFIG_KEY;
    }

    /**
     * The four modes. Starward Mode and Plight are extra challenges on top of the normal part (like Deadly Assault
     * Hard): optional content, so no reminder unless the player asks. Anomaly Arbitration is off by default too.
     */
    final public static function getEndgameDefinitions(): array
    {
        $withStarward = static fn (string $key, string $name): EndgameDefinition => new EndgameDefinition($key, $name, [
            new EndgameVariant(EndgameVariant::DEFAULT_KEY, $name),
            new EndgameVariant(self::ENDGAME_VARIANT_STARWARD, "$name (Starward)", EndgameReminder::Off),
        ]);

        return [
            $withStarward(self::ENDGAME_MEMORY_OF_CHAOS, 'Memory of Chaos'),
            $withStarward(self::ENDGAME_PURE_FICTION, 'Pure Fiction'),
            $withStarward(self::ENDGAME_APOCALYPTIC_SHADOW, 'Apocalyptic Shadow'),
            new EndgameDefinition(self::ENDGAME_ANOMALY_ARBITRATION, 'Anomaly Arbitration', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Anomaly Arbitration', EndgameReminder::Off),
                new EndgameVariant(self::ENDGAME_VARIANT_PLIGHT, 'Anomaly Arbitration (Plight)', EndgameReminder::Off),
            ]),
        ];
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account, bool $fresh = false): array
    {
        // One response per mode, whose fetch time every result of the mode carries
        $entry = $this->requestEndgameMode($definition->key, $account, $fresh);

        return $this->withEndgameFetchTime(match ($definition->key) {
            self::ENDGAME_MEMORY_OF_CHAOS => $this->readChallengeEndgames(
                $definition,
                $account,
                $this->denormalizeChallenge($entry['data']),
                HonkaiStarRailChallengeData::MEMORY_OF_CHAOS_MAX_STARS
            ),
            self::ENDGAME_PURE_FICTION => $this->readChallengeEndgames(
                $definition,
                $account,
                $this->denormalizeChallenge($entry['data']),
                HonkaiStarRailChallengeData::STORY_MAX_STARS
            ),
            self::ENDGAME_APOCALYPTIC_SHADOW => $this->readChallengeEndgames(
                $definition,
                $account,
                $this->denormalizeChallenge($entry['data']),
                HonkaiStarRailChallengeData::STORY_MAX_STARS
            ),
            self::ENDGAME_ANOMALY_ARBITRATION => $this->readAnomalyArbitrationEndgames(
                $definition,
                $account,
                $this->denormalizeAnomalyArbitration($entry['data'])
            ),
        }, $entry);
    }

    public function getUrlMemoryOfChaos(): string
    {
        return self::ENDGAMES_BASE_URL . 'challenge';
    }

    public function getUrlPureFiction(): string
    {
        return self::ENDGAMES_BASE_URL . 'challenge_story';
    }

    public function getUrlApocalypticShadow(): string
    {
        return self::ENDGAMES_BASE_URL . 'challenge_boss';
    }

    public function getUrlAnomalyArbitration(): string
    {
        return self::ENDGAMES_BASE_URL . 'challenge_peak';
    }

    /**
     * Memory of Chaos of the current period, with the stages reached.
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getMemoryOfChaos(RuntimeAccountDataInterface $account, bool $fresh = false): HonkaiStarRailChallengeData
    {
        return $this->denormalizeChallenge($this->requestEndgameMode(self::ENDGAME_MEMORY_OF_CHAOS, $account, $fresh)['data']);
    }

    /**
     * Pure Fiction of the current period, with the stages reached.
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getPureFiction(RuntimeAccountDataInterface $account, bool $fresh = false): HonkaiStarRailChallengeData
    {
        return $this->denormalizeChallenge($this->requestEndgameMode(self::ENDGAME_PURE_FICTION, $account, $fresh)['data']);
    }

    /**
     * Apocalyptic Shadow of the current period, with the stages reached.
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getApocalypticShadow(RuntimeAccountDataInterface $account, bool $fresh = false): HonkaiStarRailChallengeData
    {
        return $this->denormalizeChallenge($this->requestEndgameMode(self::ENDGAME_APOCALYPTIC_SHADOW, $account, $fresh)['data']);
    }

    /**
     * Anomaly Arbitration: the current run.
     *
     * @param bool $fresh skip the endgames cache (the new response replaces the cached one)
     */
    public function getAnomalyArbitration(RuntimeAccountDataInterface $account, bool $fresh = false): AnomalyArbitrationData
    {
        return $this->denormalizeAnomalyArbitration($this->requestEndgameMode(self::ENDGAME_ANOMALY_ARBITRATION, $account, $fresh)['data']);
    }

    /**
     * The battle record request of a mode, through the endgames cache. Star Rail answers "-10001 invalid request"
     * without the DS header, like the notes.
     *
     * @return array{fetchedAt: int, data: array<string, mixed>}
     */
    private function requestEndgameMode(string $mode, RuntimeAccountDataInterface $account, bool $fresh): array
    {
        [$url, $exceptionClass, $extraQuery] = match ($mode) {
            self::ENDGAME_MEMORY_OF_CHAOS => [$this->getUrlMemoryOfChaos(), RetrieveMemoryOfChaosDataException::class, ['need_all' => 'true']],
            self::ENDGAME_PURE_FICTION => [$this->getUrlPureFiction(), RetrievePureFictionDataException::class, ['need_all' => 'true']],
            self::ENDGAME_APOCALYPTIC_SHADOW => [$this->getUrlApocalypticShadow(), RetrieveApocalypticShadowDataException::class, ['need_all' => 'true']],
            self::ENDGAME_ANOMALY_ARBITRATION => [$this->getUrlAnomalyArbitration(), RetrieveAnomalyArbitrationDataException::class, []],
        };

        $profile = $account->getGameProfile();

        return $this->requestCachedEndgame(
            $account,
            $url,
            [
                'role_id' => $profile->getGameUid(),
                'server' => $profile->getRegion(),
                'schedule_type' => 1,
            ] + $extraQuery,
            $exceptionClass,
            $fresh,
            withDs: true
        );
    }

    /**
     * Memory of Chaos, Pure Fiction and Apocalyptic Shadow share one shape.
     *
     * @param array<string, mixed> $data
     */
    private function denormalizeChallenge(array $data): HonkaiStarRailChallengeData
    {
        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyDates($data, self::listDatePaths($data, 'groups', [])),
            HonkaiStarRailChallengeData::class
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function denormalizeAnomalyArbitration(array $data): AnomalyArbitrationData
    {
        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyDates($data, self::listDatePaths($data, 'challenge_peak_records', ['group'])),
            AnomalyArbitrationData::class
        );
    }

    /**
     * The begin_time and end_time paths of every period of a list ($path), for withoutEmptyDates().
     *
     * @param list<string> $inItem path from an item of the list to the object that holds the dates
     * @return list<list<string>>
     */
    private static function listDatePaths(mixed $data, string $path, array $inItem): array
    {
        if (!is_array($data) || !is_array($data[$path] ?? null)) {
            return [];
        }

        $paths = [];
        foreach (array_keys($data[$path]) as $index) {
            foreach (['begin_time', 'end_time'] as $date) {
                $paths[] = [$path, (string) $index, ...$inItem, $date];
            }
        }

        return $paths;
    }

    /**
     * @return list<GameEndgame> normal part, then Starward Mode
     */
    private function readChallengeEndgames(
        EndgameDefinition $definition,
        RuntimeAccountDataInterface $account,
        HonkaiStarRailChallengeData $data,
        int $maxStars,
    ): array
    {
        $region = (string) $account->getGameProfile()->getRegion();
        $endTime = null;
        foreach ($data->getPeriods() ?? [] as $period) {
            $endTime = $this->getEndOfCurrentPeriod($period, $region);
            if ($endTime !== null) {
                break;
            }
        }

        $normal = new GameEndgame()
            ->setName($definition->getVariant(EndgameVariant::DEFAULT_KEY)?->name ?? $definition->name)
            ->setVariant(EndgameVariant::DEFAULT_KEY)
            ->setEndTime($endTime)
            ->setPlayed($data->isPlayed())
            ->setMaxed($data->isMaxed($maxStars))
            ->addProgressLine(sprintf('%d/%d ★', $data->getStars(), $maxStars));
        // Only the stages the response lists: a stage that is not there gets no line
        foreach ($data->getFloors() ?? [] as $floor) {
            if (!$floor->isFullyCleared()) {
                $normal->addProgressLine(sprintf('↳ %s: %d/%d ★', $floor->getLabel() ?? 'Stage', $floor->getBaseStars(), HonkaiStarRailChallengeFloor::MAX_STARS));
            }
        }

        $starward = new GameEndgame()
            ->setName($definition->getVariant(self::ENDGAME_VARIANT_STARWARD)?->name ?? $definition->name)
            ->setVariant(self::ENDGAME_VARIANT_STARWARD)
            ->setEndTime($endTime)
            ->setPlayed($data->isStarwardPlayed())
            ->setMaxed($data->isStarwardMaxed())
            ->addProgressLine(sprintf(
                'Prismatic %d/%d ★',
                min($data->getExtraStars(), HonkaiStarRailChallengeData::STARWARD_MAX_STARS),
                HonkaiStarRailChallengeData::STARWARD_MAX_STARS
            ));

        return [$normal, $starward];
    }

    /**
     * @return list<GameEndgame> normal part, then Plight
     */
    private function readAnomalyArbitrationEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account, AnomalyArbitrationData $anomalyArbitration): array
    {
        $region = (string) $account->getGameProfile()->getRegion();

        // The record of the current run: the one whose dates contain now. None (between two runs, or empty dates):
        // the end is unknown and the handler sends nothing
        $record = new AnomalyArbitrationRecord();
        $endTime = null;
        foreach ($anomalyArbitration->getRecords() ?? [] as $candidate) {
            $endTime = $this->getEndOfCurrentPeriod($candidate->getPeriod(), $region);
            if ($endTime !== null) {
                $record = $candidate;
                break;
            }
        }

        $normal = new GameEndgame()
            ->setName($definition->getVariant(EndgameVariant::DEFAULT_KEY)?->name ?? $definition->name)
            ->setVariant(EndgameVariant::DEFAULT_KEY)
            ->setEndTime($endTime)
            ->setPlayed($record->isPlayed())
            ->setMaxed($record->isMaxed())
            ->addProgressLine(sprintf('Knights %d/%d ★', $record->getKnightStars(), AnomalyArbitrationRecord::KNIGHTS_MAX_STARS))
            ->addProgressLine(sprintf('King %d/%d ★', $record->getKingStars(), AnomalyArbitrationRecord::KING_MAX_STARS));

        $plight = new GameEndgame()
            ->setName($definition->getVariant(self::ENDGAME_VARIANT_PLIGHT)?->name ?? $definition->name)
            ->setVariant(self::ENDGAME_VARIANT_PLIGHT)
            ->setEndTime($endTime)
            ->setPlayed($record->isPlightPlayed())
            ->setMaxed($record->isPlightMaxed());
        if ($record->isPlightCleared()) {
            $plight->addProgressLine('Cleared');
        }

        return [$normal, $plight];
    }

    /**
     * End of the period when it is the current one (begin <= now < end), null otherwise or without dates. The
     * "status" of the responses is not used: it cannot be trusted.
     */
    private function getEndOfCurrentPeriod(?HonkaiStarRailEndgamePeriod $period, string $region): ?\DateTimeImmutable
    {
        $begin = $this->toServerInstant($period?->getBeginTime(), $region);
        $end = $this->toServerInstant($period?->getEndTime(), $region);
        if ($begin === null || $end === null) {
            return null;
        }

        $now = $this->endgamesClock?->now() ?? new \DateTimeImmutable();

        return $begin <= $now && $now < $end ? $end : null;
    }
}
