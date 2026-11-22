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
        yield '24,999 rated A' => [24999, 'A', false];
        yield '25,000 rated S' => [25000, 'S', true];
        yield '24,999 without rating' => [24999, null, false];
        yield '25,000 without rating' => [25000, null, true];
        yield 'rated S below the score' => [24000, 'S', true];
    }

    #[DataProvider('sRankProvider')]
    public function testShiyuDefenseStageIsSRankFrom25000PointsOrWithTheSRating(int $score, ?string $rating, bool $expected): void
    {
        $this->assertSame($expected, self::stage($score, $rating)->isSRank());
    }

    public function testShiyuDefenseIsFullyClearedOnlyWithThreeSRankStages(): void
    {
        $allS = new ShiyuDefenseData()->setStages([self::stage(25000, 'S'), self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertSame(3, $allS->getSRankStageCount());
        $this->assertTrue($allS->isFullyCleared());

        $oneBelow = new ShiyuDefenseData()->setStages([self::stage(24999, 'A'), self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertSame(2, $oneBelow->getSRankStageCount());
        $this->assertFalse($oneBelow->isFullyCleared());

        // A stage missing from the list counts as not at S rank
        $twoListed = new ShiyuDefenseData()->setStages([self::stage(38000, 'S'), self::stage(50000, 'S')]);
        $this->assertFalse($twoListed->isFullyCleared());

        $this->assertFalse(new ShiyuDefenseData()->isFullyCleared());
    }

    public function testShiyuDefenseIsPlayedWithAnyScore(): void
    {
        $this->assertFalse(new ShiyuDefenseData()->isPlayed());
        $this->assertFalse(new ShiyuDefenseData()->setScore(0)->setStages([self::stage(0, null)])->isPlayed());
        $this->assertTrue(new ShiyuDefenseData()->setScore(0)->setStages([self::stage(0, null), self::stage(1200, 'B')])->isPlayed());
        // Summary without the per-stage detail
        $this->assertTrue(new ShiyuDefenseData()->setScore(1200)->isPlayed());
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
        $this->assertFalse($eight->isHardUnlocked());

        $nine = new DeadlyAssaultData()->setHasData(true)->setChallenges(self::challenges([[30000, 3], [20000, 3], [41000, 3]]));
        $this->assertSame(9, $nine->getStars());
        $this->assertTrue($nine->isFullyCleared());
        $this->assertTrue($nine->isHardUnlocked());

        // Per boss: three stars
        $this->assertTrue($nine->getChallenges()[1]->isFullyCleared());
        $this->assertFalse($eight->getChallenges()[1]->isFullyCleared());
    }

    public function testDeadlyAssaultHardMode(): void
    {
        $nine = [[30000, 3], [20000, 3], [41000, 3]];

        $locked = new DeadlyAssaultData()->setChallenges(self::challenges([[30000, 3], [19999, 2], [41000, 3]]))
            ->setHardChallenges(self::challenges([[31000, 3]]));
        $this->assertFalse($locked->isHardUnlocked());

        $notPlayed = new DeadlyAssaultData()->setChallenges(self::challenges($nine))->setHardChallenges([]);
        $this->assertTrue($notPlayed->isHardUnlocked());
        $this->assertFalse($notPlayed->isHardPlayed());
        $this->assertFalse($notPlayed->isHardFullyCleared());

        $twoStars = new DeadlyAssaultData()->setChallenges(self::challenges($nine))->setHardChallenges(self::challenges([[29999, 2]]));
        $this->assertTrue($twoStars->isHardPlayed());
        $this->assertSame(2, $twoStars->getHardStars());
        $this->assertFalse($twoStars->isHardFullyCleared());

        $threeStars = new DeadlyAssaultData()->setChallenges(self::challenges($nine))->setHardChallenges(self::challenges([[30000, 3]]));
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
        $noData = new DeadlyAssaultData()->setHasData(false)->setTotalScore(90000)->setTotalStar(9)
            ->setChallenges(self::challenges([[30000, 3], [30000, 3], [30000, 3]]))
            ->setHardChallenges(self::challenges([[30000, 3]]));
        $this->assertFalse($noData->isPlayed());
        $this->assertSame(0, $noData->getStars());
        $this->assertFalse($noData->isHardUnlocked());
        $this->assertFalse($noData->isHardPlayed());

        // Without the per-boss list the total is used
        $totalsOnly = new DeadlyAssaultData()->setTotalScore(95000)->setTotalStar(9);
        $this->assertTrue($totalsOnly->isPlayed());
        $this->assertTrue($totalsOnly->isFullyCleared());
    }
}
