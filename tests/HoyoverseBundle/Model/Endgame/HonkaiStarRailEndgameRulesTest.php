<?php

namespace App\Tests\HoyoverseBundle\Model\Endgame;

use App\HoyoverseBundle\Model\Endgame\AnomalyArbitrationBossRecord;
use App\HoyoverseBundle\Model\Endgame\AnomalyArbitrationRecord;
use App\HoyoverseBundle\Model\Endgame\HonkaiStarRailChallengeData;
use App\HoyoverseBundle\Model\Endgame\HonkaiStarRailChallengeFloor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Played / maximum rules of the Honkai: Star Rail endgame modes.
 */
class HonkaiStarRailEndgameRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int|string|null, 1: bool}>
     */
    public static function battleProvider(): iterable
    {
        yield 'no battle' => [0, false];
        yield 'a battle' => [1, true];
        yield 'three battles' => [3, true];
        yield 'battles as a string' => ['2', true];
        yield 'zero as a string' => ['0', false];
        yield 'missing' => [null, false];
    }

    #[DataProvider('battleProvider')]
    public function testPlayedIsAtLeastOneBattle(int|string|null $battles, bool $expected): void
    {
        $this->assertSame($expected, new HonkaiStarRailChallengeData()->setBattleNum($battles)->isPlayed());
    }

    public function testStarsWithoutABattleAreNotPlayed(): void
    {
        // Quick-cleared stages credit their stars without a fight
        $data = new HonkaiStarRailChallengeData()->setStarNum(27)->setBattleNum(0)->setHasData(true);

        $this->assertFalse($data->isPlayed());
    }

    /**
     * @return iterable<string, array{0: int|string|null, 1: int, 2: bool}>
     */
    public static function maximumProvider(): iterable
    {
        yield 'Memory of Chaos at 36' => [36, HonkaiStarRailChallengeData::MEMORY_OF_CHAOS_MAX_STARS, true];
        yield 'Memory of Chaos at 35' => [35, HonkaiStarRailChallengeData::MEMORY_OF_CHAOS_MAX_STARS, false];
        yield 'Pure Fiction at 12' => [12, HonkaiStarRailChallengeData::STORY_MAX_STARS, true];
        yield 'Pure Fiction at 11' => [11, HonkaiStarRailChallengeData::STORY_MAX_STARS, false];
        yield 'stars as a string' => ['12', HonkaiStarRailChallengeData::STORY_MAX_STARS, true];
        yield 'missing' => [null, HonkaiStarRailChallengeData::STORY_MAX_STARS, false];
    }

    #[DataProvider('maximumProvider')]
    public function testMaximumIsEveryStarOfTheMode(int|string|null $stars, int $maxStars, bool $expected): void
    {
        $this->assertSame($expected, new HonkaiStarRailChallengeData()->setStarNum($stars)->isMaxed($maxStars));
    }

    public function testStarwardPlayedIsAStagePlayedWithThreeTeamsOrThePrismaticStar(): void
    {
        $normal = new HonkaiStarRailChallengeFloor()->setName('Example (XII)')->setStarNum(3)->setExtraStarNum(0)->setTierce(false);
        $starward = new HonkaiStarRailChallengeFloor()->setName('Example (XII)Starward Mode')->setStarNum(3)->setExtraStarNum(0)->setTierce(true);

        $this->assertFalse(new HonkaiStarRailChallengeData()->isStarwardPlayed());
        $this->assertFalse(new HonkaiStarRailChallengeData()->setFloors([$normal])->isStarwardPlayed());
        // Played in Starward Mode without the Prismatic Star: played, not at the maximum
        $played = new HonkaiStarRailChallengeData()->setFloors([$normal, $starward])->setExtraStarNum(0);
        $this->assertTrue($played->isStarwardPlayed());
        $this->assertFalse($played->isStarwardMaxed());
        // The Prismatic Star alone also counts as played
        $this->assertTrue(new HonkaiStarRailChallengeData()->setExtraStarNum('1')->isStarwardPlayed());
        $this->assertTrue(new HonkaiStarRailChallengeData()->setExtraStarNum('1')->isStarwardMaxed());
    }

    public function testFloorStarsExcludeThePrismaticStar(): void
    {
        $floor = new HonkaiStarRailChallengeFloor()->setStarNum(4)->setExtraStarNum(1);

        $this->assertSame(3, $floor->getBaseStars());
        $this->assertSame(1, $floor->getExtraStars());
        $this->assertTrue($floor->isFullyCleared());
        $this->assertSame(2, new HonkaiStarRailChallengeFloor()->setStarNum(2)->setExtraStarNum(0)->getBaseStars());
        $this->assertFalse(new HonkaiStarRailChallengeFloor()->setStarNum(2)->setExtraStarNum(0)->isFullyCleared());
    }

    public function testFloorStarsAsStrings(): void
    {
        // Apocalyptic Shadow sends the stars of its stages as strings
        $floor = new HonkaiStarRailChallengeFloor()->setStarNum('4')->setExtraStarNum('1');

        $this->assertSame(3, $floor->getBaseStars());
        $this->assertSame(1, $floor->getExtraStars());
        $this->assertSame(0, new HonkaiStarRailChallengeFloor()->setStarNum('abc')->getBaseStars());
        $this->assertSame(0, new HonkaiStarRailChallengeFloor()->getBaseStars());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'plain stage' => ['Example Memory (XI)', 'Example Memory (XI)'];
        yield 'Starward suffix, no space' => ['Example Memory (XII)Starward Mode', 'Example Memory (XII)'];
        yield 'difficulty' => ['Example Shadow: Difficulty 4Starward Mode', 'Example Shadow: Difficulty 4'];
        yield 'zero-padded difficulty' => ['Example Shadow: Difficulty 04', 'Example Shadow: Difficulty 04'];
        yield 'only the suffix' => ['Starward Mode', 'Starward Mode'];
        yield 'empty' => ['', null];
        yield 'missing' => [null, null];
    }

    #[DataProvider('labelProvider')]
    public function testStageLabelIsTheNameWithoutTheStarwardSuffix(?string $name, ?string $expected): void
    {
        $this->assertSame($expected, new HonkaiStarRailChallengeFloor()->setName($name)->getLabel());
    }

    private static function record(bool $hasRecord, int|string|null $knights, int|string|null $king, ?bool $hardMode): AnomalyArbitrationRecord
    {
        return new AnomalyArbitrationRecord()
            ->setHasChallengeRecord($hasRecord)
            ->setMobStars($knights)
            ->setBossStars($king)
            ->setBossRecord($hardMode === null ? null : new AnomalyArbitrationBossRecord()->setHardMode($hardMode)->setStarNum($king));
    }

    /**
     * @return iterable<string, array{0: bool, 1: int|string|null, 2: int|string|null, 3: ?bool, 4: bool, 5: bool, 6: bool}>
     */
    public static function anomalyArbitrationProvider(): iterable
    {
        // has record, Knights, King, hard_mode (null = no boss_record), played, maximum, Plight cleared
        yield 'never played' => [false, 0, 0, null, false, false, false];
        yield 'Knights only' => [true, 6, 0, null, true, false, false];
        yield 'King below 3 stars' => [true, 9, 2, false, true, false, false];
        yield 'Knights below 9 stars' => [true, 8, 3, false, true, false, false];
        yield 'every star' => [true, 9, 3, false, true, true, false];
        yield 'stars as strings' => [true, '9', '3', false, true, true, false];
        yield 'Plight cleared below the stars' => [true, 9, 2, true, true, true, true];
        yield 'Plight cleared without Knights' => [true, 0, 1, true, true, true, true];
        yield 'Plight cleared without a record flag' => [false, 0, 1, true, true, true, true];
    }

    #[DataProvider('anomalyArbitrationProvider')]
    public function testAnomalyArbitrationRules(bool $hasRecord, int|string|null $knights, int|string|null $king, ?bool $hardMode, bool $played, bool $maxed, bool $plight): void
    {
        $record = self::record($hasRecord, $knights, $king, $hardMode);

        $this->assertSame($played, $record->isPlayed());
        $this->assertSame($maxed, $record->isMaxed());
        // Plight: pass/fail, played and maximum are the same thing
        $this->assertSame($plight, $record->isPlightCleared());
        $this->assertSame($plight, $record->isPlightPlayed());
        $this->assertSame($plight, $record->isPlightMaxed());
    }

    public function testPlightWithoutStarsIsClearedByHardModeAlone(): void
    {
        // No threshold on the stars of the King: hard_mode is the whole rule
        $record = new AnomalyArbitrationRecord()->setBossRecord(new AnomalyArbitrationBossRecord()->setHardMode(true)->setStarNum(0));

        $this->assertTrue($record->isPlightCleared());
        $this->assertFalse(new AnomalyArbitrationRecord()->setBossRecord(new AnomalyArbitrationBossRecord()->setStarNum(3))->isPlightCleared());
        $this->assertFalse(new AnomalyArbitrationRecord()->isPlightCleared());
    }
}
