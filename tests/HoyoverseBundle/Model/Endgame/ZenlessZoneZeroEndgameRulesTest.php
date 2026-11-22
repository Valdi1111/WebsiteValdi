<?php

namespace App\Tests\HoyoverseBundle\Model\Endgame;

use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultBoss;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultChallenge;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultData;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseData;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Played / maximum rules of the Zenless Zone Zero endgame modes.
 */
class ZenlessZoneZeroEndgameRulesTest extends TestCase
{
    private static function stage(int $score, ?string $rating): ShiyuDefenseStage
    {
        return new ShiyuDefenseStage()->setScore($score)->setRating($rating)->setMaxScore(50000);
    }

    /**
     * @param list<array{0: int, 1: int}> $scoresAndStars
     * @return list<DeadlyAssaultChallenge>
     */
    private static function challenges(array $scoresAndStars): array
    {
        return array_map(
            static fn (array $boss) => new DeadlyAssaultChallenge()
                ->setScore($boss[0])
                ->setStar($boss[1])
                ->setBosses([new DeadlyAssaultBoss()->setName('Example Boss')]),
            $scoresAndStars
        );
    }

    /**
     * @return iterable<string, array{0: int, 1: ?string, 2: bool}>
     */
    public static function sRankProvider(): iterable
    {
        yield 'rated A' => [24999, 'A', false];
        // No score threshold: only the rating counts
        yield 'rated A with a high score' => [45000, 'A', false];
        yield 'rated S' => [25000, 'S', true];
        yield 'rated S below 25,000 points' => [24000, 'S', true];
        yield 'rated S+' => [48000, 'S+', true];
        yield 'not rated' => [40000, null, false];
    }

    #[DataProvider('sRankProvider')]
    public function testShiyuDefenseStageIsSRankOnlyWithTheSRating(int $score, ?string $rating, bool $expected): void
    {
        $this->assertSame($expected, self::stage($score, $rating)->isSRank());
    }

    public function testShiyuDefenseIsFullyClearedWithFloorFourAndThreeStagesAtS(): void
    {
        $allS = new ShiyuDefenseData()->setFourthFloorRating('S')->setStages([self::stage(25000, 'S'), self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertSame(3, $allS->getSRankStageCount());
        $this->assertTrue($allS->isFullyCleared());

        $oneBelow = new ShiyuDefenseData()->setFourthFloorRating('S')->setStages([self::stage(24999, 'A'), self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertSame(2, $oneBelow->getSRankStageCount());
        $this->assertFalse($oneBelow->isFullyCleared());

        // Floor 4 below S: not at the maximum even with the fifth floor done
        $floorFourA = new ShiyuDefenseData()->setFourthFloorRating('A')->setStages([self::stage(25000, 'S'), self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertFalse($floorFourA->isFullyCleared());

        // A stage missing from the list counts as not at S rank
        $twoListed = new ShiyuDefenseData()->setFourthFloorRating('S')->setStages([self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertFalse($twoListed->isFullyCleared());

        $this->assertFalse(new ShiyuDefenseData()->isFullyCleared());
    }

    public function testShiyuDefenseIsPlayedFromTheFourthFloor(): void
    {
        $this->assertTrue(new ShiyuDefenseData()->setFourthFloorRating('B')->isPlayed());
        // Teams sent without a rating yet
        $this->assertTrue(new ShiyuDefenseData()->setFourthFloorChallenges([['layer_id' => 1]])->isPlayed());
        $this->assertFalse(new ShiyuDefenseData()->setFourthFloorRating('')->setFourthFloorChallenges([])->isPlayed());

        $floorFourOnly = new ShiyuDefenseData()->setFourthFloorRating('S');
        $this->assertTrue($floorFourOnly->isFourthFloorPlayed());
        $this->assertTrue($floorFourOnly->isFourthFloorSRank());
        $this->assertFalse($floorFourOnly->isFifthFloorPlayed());
        $this->assertFalse($floorFourOnly->isFullyCleared());
    }

    public function testShiyuDefenseIsPlayedWithAnyScore(): void
    {
        $this->assertFalse(new ShiyuDefenseData()->isPlayed());
        $this->assertFalse(new ShiyuDefenseData()->setScore(0)->setStages([self::stage(0, null)])->isPlayed());
        $this->assertTrue(new ShiyuDefenseData()->setScore(0)->setStages([self::stage(0, null), self::stage(1200, 'B')])->isPlayed());
        // Summary without the per-stage detail
        $this->assertTrue(new ShiyuDefenseData()->setScore(1200)->isPlayed());
    }

    public function testShiyuDefenseStagesWithRatingAreAlwaysPlayed(): void
    {
        // Rated stages with no score in the response: at the maximum, so not "not played"
        $data = new ShiyuDefenseData()->setFourthFloorRating('S')->setStages([self::stage(0, 'S'), self::stage(0, 'S'), self::stage(0, 'S')]);
        $this->assertTrue($data->isFullyCleared());
        $this->assertTrue($data->isPlayed());

        $this->assertTrue(new ShiyuDefenseData()->setStages([self::stage(0, 'B')])->isPlayed());
        $this->assertFalse(new ShiyuDefenseData()->setStages([self::stage(0, null), self::stage(0, '')])->isPlayed());
    }

    public function testDeadlyAssaultWithStarsIsAlwaysPlayed(): void
    {
        $data = new DeadlyAssaultData()->setChallenges(self::challenges([[0, 3], [0, 3], [0, 3]]));
        $this->assertTrue($data->isFullyCleared());
        $this->assertTrue($data->isPlayed());

        $this->assertTrue(new DeadlyAssaultData()->setTotalStar(4)->isPlayed());
        $this->assertFalse(new DeadlyAssaultData()->setChallenges(self::challenges([[0, 0], [0, 0], [0, 0]]))->isPlayed());
        $this->assertFalse(new DeadlyAssaultData()->setHasData(false)->setTotalStar(9)->isPlayed());
    }

    public function testDeadlyAssaultHardWithStarsIsPlayed(): void
    {
        $data = new DeadlyAssaultData()
            ->setHasHard(true)
            ->setChallenges(self::challenges([[1, 3], [1, 3], [1, 3]]))
            ->setHardChallenges(self::challenges([[0, 2]]));

        $this->assertTrue($data->isHardUnlocked());
        $this->assertTrue($data->isHardPlayed());
        $this->assertFalse(new DeadlyAssaultData()->setHardChallenges(self::challenges([[0, 0]]))->isHardPlayed());
    }

    public function testShiyuDefenseVersions(): void
    {
        $this->assertTrue(new ShiyuDefenseData()->setVersion('v2')->isSupportedVersion());
        $this->assertTrue(new ShiyuDefenseData()->isSupportedVersion());
        $this->assertFalse(new ShiyuDefenseData()->setVersion('v1')->isSupportedVersion());
        $this->assertFalse(new ShiyuDefenseData()->setVersion('v3')->isSupportedVersion());
    }

    public function testDeadlyAssaultNeedsNineStars(): void
    {
        $eight = new DeadlyAssaultData()->setHasData(true)->setChallenges(self::challenges([[30000, 3], [19999, 2], [41000, 3]]));
        $this->assertTrue($eight->isPlayed());
        $this->assertSame(8, $eight->getStars());
        $this->assertFalse($eight->isFullyCleared());

        $nine = new DeadlyAssaultData()->setHasData(true)->setChallenges(self::challenges([[30000, 3], [20000, 3], [41000, 3]]));
        $this->assertSame(9, $nine->getStars());
        $this->assertTrue($nine->isFullyCleared());

        // Per boss: three stars
        $this->assertTrue($nine->getChallenges()[1]->isFullyCleared());
        $this->assertFalse($eight->getChallenges()[1]->isFullyCleared());
    }

    public function testDeadlyAssaultHardModeIsUnlockedWithNineStarsOrHardData(): void
    {
        $nine = self::challenges([[30000, 3], [20000, 3], [41000, 3]]);
        $eight = self::challenges([[30000, 3], [19999, 2], [41000, 3]]);

        // Seen live: 9★ and Hard mode not tried yet, has_hard false
        $this->assertTrue(new DeadlyAssaultData()->setHasData(true)->setChallenges($nine)->setHasHard(false)->isHardUnlocked());
        // Totals before the boss list
        $this->assertTrue(new DeadlyAssaultData()->setHasData(true)->setTotalStar(9)->setChallenges([])->isHardUnlocked());
        // Hard mode data
        $this->assertTrue(new DeadlyAssaultData()->setHasData(true)->setChallenges($eight)->setHasHard(true)->isHardUnlocked());

        $this->assertFalse(new DeadlyAssaultData()->setHasData(true)->setChallenges($eight)->setHasHard(false)->isHardUnlocked());
        $this->assertFalse(new DeadlyAssaultData()->setHasData(false)->setTotalStar(9)->setHasHard(true)->isHardUnlocked());
    }

    public function testDeadlyAssaultHardMode(): void
    {
        $nine = [[30000, 3], [20000, 3], [41000, 3]];

        $notPlayed = new DeadlyAssaultData()->setHasHard(true)->setChallenges(self::challenges($nine))->setHardChallenges([]);
        $this->assertTrue($notPlayed->isHardUnlocked());
        $this->assertFalse($notPlayed->isHardPlayed());
        $this->assertFalse($notPlayed->isHardFullyCleared());

        $twoStars = new DeadlyAssaultData()->setHasHard(true)->setChallenges(self::challenges($nine))->setHardChallenges(self::challenges([[29999, 2]]));
        $this->assertTrue($twoStars->isHardPlayed());
        $this->assertSame(2, $twoStars->getHardStars());
        $this->assertFalse($twoStars->isHardFullyCleared());

        $threeStars = new DeadlyAssaultData()->setHasHard(true)->setChallenges(self::challenges($nine))->setHardChallenges(self::challenges([[30000, 3]]));
        $this->assertTrue($threeStars->isHardFullyCleared());
    }

    public function testDeadlyAssaultWithoutData(): void
    {
        $empty = new DeadlyAssaultData();
        $this->assertFalse($empty->isPlayed());
        $this->assertSame(0, $empty->getStars());
        $this->assertFalse($empty->isHardUnlocked());
        $this->assertFalse($empty->isHardPlayed());

        // has_data = false wins over any leftover value
        $noData = new DeadlyAssaultData()->setHasData(false)->setHasHard(true)->setTotalScore(90000)->setTotalStar(9)
            ->setChallenges(self::challenges([[30000, 3], [30000, 3], [30000, 3]]))
            ->setHardChallenges(self::challenges([[30000, 3]]));
        $this->assertFalse($noData->isPlayed());
        $this->assertSame(0, $noData->getStars());
        $this->assertFalse($noData->isHardUnlocked());
        $this->assertFalse($noData->isHardPlayed());

        // Without the per-boss list the total is used (seen live right after a run: the list comes later)
        $totalsOnly = new DeadlyAssaultData()->setHasData(true)->setTotalScore(94473)->setTotalStar(9)->setChallenges([]);
        $this->assertTrue($totalsOnly->isPlayed());
        $this->assertTrue($totalsOnly->isFullyCleared());
    }
}
