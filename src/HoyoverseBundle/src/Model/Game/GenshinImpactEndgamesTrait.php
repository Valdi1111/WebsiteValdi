<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\RetrieveSpiralAbyssDataException;
use App\HoyoverseBundle\Exception\RetrieveStygianOnslaughtDataException;
use App\HoyoverseBundle\Exception\RetrieveTheaterLobbyDataException;
use App\HoyoverseBundle\Model\Endgame\SpiralAbyssData;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtData;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtType;
use App\HoyoverseBundle\Model\Endgame\TheaterLobbyData;
use App\HoyoverseBundle\Model\Endgame\TheaterStat;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait GenshinImpactEndgamesTrait
{
    public const string ENDGAMES_CONFIG_KEY = 'gi';

    public const string ENDGAME_SPIRAL_ABYSS = 'spiral_abyss';

    public const string ENDGAME_IMAGINARIUM_THEATER = 'imaginarium_theater';

    public const string ENDGAME_STYGIAN_ONSLAUGHT = 'stygian_onslaught';

    private ?ClockInterface $endgamesClock = null;

    public function getEndgamesClock(): ?ClockInterface
    {
        return $this->endgamesClock;
    }

    /**
     * "Now" of the Stygian Onslaught period choice (the current time when no clock is injected).
     */
    #[Required]
    public function setEndgamesClock(ClockInterface $endgamesClock): void
    {
        $this->endgamesClock = $endgamesClock;
    }

    final public static function getEndgamesConfigKey(): string
    {
        return self::ENDGAMES_CONFIG_KEY;
    }

    /**
     * Spiral Abyss, then Imaginarium Theater and Stygian Onslaught: these two are played at a difficulty the player
     * picks, one record per period, so the player is reminded up to a difficulty (levels) instead of a Full Clear.
     */
    final public static function getEndgameDefinitions(): array
    {
        return [
            new EndgameDefinition(self::ENDGAME_SPIRAL_ABYSS, 'Spiral Abyss'),
            new EndgameDefinition(self::ENDGAME_IMAGINARIUM_THEATER, 'Imaginarium Theater', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Imaginarium Theater', levels: self::createLevels(TheaterStat::DIFFICULTIES)),
            ]),
            new EndgameDefinition(self::ENDGAME_STYGIAN_ONSLAUGHT, 'Stygian Onslaught', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Stygian Onslaught', levels: self::createLevels(StygianOnslaughtType::DIFFICULTIES)),
            ]),
        ];
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account): array
    {
        return match ($definition->key) {
            self::ENDGAME_SPIRAL_ABYSS => $this->readSpiralAbyssEndgames($definition, $account),
            self::ENDGAME_IMAGINARIUM_THEATER => $this->readImaginariumTheaterEndgames($definition, $account),
            self::ENDGAME_STYGIAN_ONSLAUGHT => $this->readStygianOnslaughtEndgames($definition, $account),
        };
    }

    public function getUrlSpiralAbyss(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/spiralAbyss";
    }

    /**
     * Spiral Abyss of the current period (schedule_type 1; 2 = the previous one).
     */
    public function getSpiralAbyss(RuntimeAccountDataInterface $account): SpiralAbyssData
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlSpiralAbyss(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
            ],
            exceptionClass: RetrieveSpiralAbyssDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            is_array($body['data'] ?? null) ? self::withoutEmptyEpochs($body['data']) : [],
            SpiralAbyssData::class
        );
    }

    public function getUrlTheaterLobby(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/role_combat";
    }

    /**
     * Imaginarium Theater, current and previous period (no period parameter), without the per-act detail: the stat
     * block is there anyway.
     */
    public function getTheaterLobby(RuntimeAccountDataInterface $account): TheaterLobbyData
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlTheaterLobby(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'need_detail' => "false",
            ],
            exceptionClass: RetrieveTheaterLobbyDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyPeriodEpochs($body['data'] ?? null),
            TheaterLobbyData::class
        );
    }

    public function getUrlStygianOnslaught(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/hard_challenge";
    }

    /**
     * Stygian Onslaught, current and previous period (no period parameter; need_detail does not change the bests).
     */
    public function getStygianOnslaught(RuntimeAccountDataInterface $account): StygianOnslaughtData
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlStygianOnslaught(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
            ],
            exceptionClass: RetrieveStygianOnslaughtDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            self::withoutEmptyPeriodEpochs($body['data'] ?? null),
            StygianOnslaughtData::class
        );
    }

    /**
     * @return list<GameEndgame>
     */
    private function readSpiralAbyssEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account): array
    {
        $spiralAbyss = $this->getSpiralAbyss($account);

        $endgame = new GameEndgame()
            ->setName($definition->name)
            ->setUnlocked($spiralAbyss->isUnlocked())
            ->setEndTime($spiralAbyss->getEndTime())
            ->setPlayed($spiralAbyss->isPlayed())
            ->setMaxed($spiralAbyss->isFullyCleared())
            ->addProgressLine(sprintf('%d/%d ★', $spiralAbyss->getStars(), SpiralAbyssData::MAX_STARS));

        // The floors played below nine stars (the floors not played are not listed)
        foreach ($spiralAbyss->getFloorsBelowMaximum() as $floor) {
            $endgame->addProgressLine(sprintf('↳ Floor %d: %d/%d ★', $floor->getIndex() ?? 0, $floor->getStar() ?? 0, $floor->getStarsAvailable()));
        }

        return [$endgame];
    }

    /**
     * @return list<GameEndgame>
     */
    private function readImaginariumTheaterEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account): array
    {
        $theater = $this->getTheaterLobby($account);

        $endgame = new GameEndgame()
            ->setName($definition->name)
            ->setUnlocked($theater->isUnlocked());

        $period = $theater->getCurrentPeriod();
        if ($period === null) {
            // No current period: a result without an end, which the reminder logs and skips
            return [$endgame];
        }

        $stat = $period->getStat() ?? new TheaterStat();
        if ($stat->hasUnknownDifficulty()) {
            $this->logUnknownEndgameDifficulty($definition, (int) $stat->getDifficultyId(), $account);

            return [];
        }

        $completed = $stat->getCompletedDifficulty();
        $endgame
            ->setEndTime($period->getSchedule()?->getEndTime())
            ->setPlayed($period->isPlayed())
            ->setCompletedLevel($completed !== null ? TheaterStat::DIFFICULTIES[$completed]['key'] : null)
            // A variant with levels is at the maximum with its top level completed
            ->setMaxed($completed === array_key_last(TheaterStat::DIFFICULTIES));

        $difficulty = $stat->getDifficulty();
        if (!$period->isPlayed() || $difficulty === null) {
            return [$endgame];
        }

        $line = sprintf('%s: Act %d/%d', $difficulty['name'], $stat->getMaxRoundId() ?? 0, $difficulty['acts']);
        if ($stat->getDifficultyId() === TheaterStat::LUNAR_DIFFICULTY) {
            $line .= sprintf(', Arcana %d/%d', $stat->getTarotFinishedCount() ?? 0, TheaterStat::LUNAR_ARCANA);
        }

        return [$endgame
            ->addProgressLine($line)
            ->addProgressLine(sprintf('%d/%d ★', $stat->getMedalNum() ?? 0, $stat->getMaxMedals()))];
    }

    /**
     * @return list<GameEndgame>
     */
    private function readStygianOnslaughtEndgames(EndgameDefinition $definition, RuntimeAccountDataInterface $account): array
    {
        $stygianOnslaught = $this->getStygianOnslaught($account);

        $endgame = new GameEndgame()
            ->setName($definition->name)
            ->setUnlocked($stygianOnslaught->isUnlocked());

        $period = $stygianOnslaught->getPeriodAt($this->endgamesClock?->now() ?? new \DateTimeImmutable());
        if ($period === null) {
            // Between two periods: a result without an end, which the reminder logs and skips
            return [$endgame];
        }

        if ($period->hasUnknownDifficulty()) {
            $this->logUnknownEndgameDifficulty($definition, (int) $period->getBest()?->getDifficulty(), $account);

            return [];
        }

        $completed = $period->getCompletedDifficulty();
        $endgame
            ->setEndTime($period->getSchedule()?->getEndTime())
            ->setPlayed($period->isPlayed())
            ->setCompletedLevel($completed !== null ? StygianOnslaughtType::DIFFICULTIES[$completed]['key'] : null)
            // A variant with levels is at the maximum with its top level completed
            ->setMaxed($completed === array_key_last(StygianOnslaughtType::DIFFICULTIES));

        if (!$period->isPlayed()) {
            return [$endgame];
        }

        $best = $period->getBest();
        if ($completed === null || $best === null) {
            return [$endgame->addProgressLine('No difficulty completed yet')];
        }

        $name = StygianOnslaughtType::DIFFICULTIES[$completed]['name'];
        $seconds = $best->getSecond();

        return [$endgame->addProgressLine($seconds !== null
            ? sprintf('Best: %s (%d:%02d)', $name, intdiv($seconds, 60), $seconds % 60)
            : sprintf('Best: %s', $name))];
    }

    /**
     * A difficulty above the known ones (a tier added by an update): the completed level cannot be told, so no
     * reminder rather than a wrong one.
     */
    private function logUnknownEndgameDifficulty(EndgameDefinition $definition, int $difficulty, RuntimeAccountDataInterface $account): void
    {
        $this->getLogger()->warning(sprintf(
            'Unknown %s difficulty %d: skipped by the endgames reminder.',
            $definition->name,
            $difficulty
        ), [
            'uid' => $account->getGameProfile()->getGameUid(),
            'region' => $account->getGameProfile()->getRegion(),
        ]);
    }

    /**
     * @param array<int, array{key: string, name: string}> $difficulties difficulty id => level key and name
     * @return list<EndgameLevel>
     */
    private static function createLevels(array $difficulties): array
    {
        return array_values(array_map(
            static fn (array $difficulty) => new EndgameLevel($difficulty['key'], $difficulty['name']),
            $difficulties
        ));
    }

    /**
     * withoutEmptyEpochs() on the schedule of every period of data[] (Imaginarium Theater, Stygian Onslaught).
     *
     * @return array<string, mixed>
     */
    private static function withoutEmptyPeriodEpochs(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (!is_array($data['data'] ?? null)) {
            return $data;
        }

        foreach ($data['data'] as $index => $period) {
            if (is_array($period) && is_array($period['schedule'] ?? null)) {
                $data['data'][$index]['schedule'] = self::withoutEmptyEpochs($period['schedule']);
            }
        }

        return $data;
    }

    /**
     * Turns the start_time / end_time that are not a positive epoch (empty string, "0") into null: no date, instead of
     * a denormalization error or a date in 1970.
     *
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private static function withoutEmptyEpochs(array $schedule): array
    {
        foreach (['start_time', 'end_time'] as $key) {
            if (array_key_exists($key, $schedule) && !(is_numeric($schedule[$key]) && (int) $schedule[$key] > 0)) {
                $schedule[$key] = null;
            }
        }

        return $schedule;
    }
}
