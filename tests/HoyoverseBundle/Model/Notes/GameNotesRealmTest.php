<?php

namespace App\Tests\HoyoverseBundle\Model\Notes;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GameNotesRealmTest extends TestCase
{
    private static function accountData(int $threshold): RuntimeAccountData
    {
        return new RuntimeAccountData()->setGameProfile(new HoyoverseGameProfile()->setRealmCurrencyThreshold($threshold));
    }

    public function testIsFull(): void
    {
        $this->assertFalse(new GameNotesRealm()->setCurrentCoin(300)->setMaxCoin(2400)->isFull());
        $this->assertTrue(new GameNotesRealm()->setCurrentCoin(2400)->setMaxCoin(2400)->isFull());
        $this->assertTrue(new GameNotesRealm()->setCurrentCoin(2410)->setMaxCoin(2400)->isFull());
    }

    /**
     * @return iterable<string, array{0: ?int, 1: ?int}>
     */
    public static function unknownAmounts(): iterable
    {
        yield 'both unknown' => [null, null];
        yield 'current unknown' => [null, 2400];
        yield 'max unknown' => [2400, null];
        yield 'zero current, max unknown' => [0, null];
    }

    #[DataProvider('unknownAmounts')]
    public function testAnUnknownAmountIsNeverFull(?int $current, ?int $max): void
    {
        $realm = new GameNotesRealm()->setCurrentCoin($current)->setMaxCoin($max);

        $this->assertFalse($realm->isFull());
        // "When full" threshold of the profile: no realm currency reminder either
        $this->assertFalse($realm->reachesThreshold(-1));
        $this->assertFalse($realm->reachesThreshold(null));
        $this->assertFalse($realm->isWithinThreshold(self::accountData(-1)));
    }

    public function testReachesThreshold(): void
    {
        $realm = new GameNotesRealm()->setCurrentCoin(1500)->setMaxCoin(2400);

        $this->assertTrue($realm->reachesThreshold(0));
        $this->assertTrue($realm->reachesThreshold(1500));
        $this->assertFalse($realm->reachesThreshold(1501));
        // Null or negative threshold: only when full
        $this->assertFalse($realm->reachesThreshold(-1));
        $this->assertFalse($realm->reachesThreshold(null));

        $realm->setCurrentCoin(2400);
        $this->assertTrue($realm->reachesThreshold(-1));
        $this->assertTrue($realm->reachesThreshold(null));
    }

    public function testAnUnknownCurrentAmountNeverReachesAThreshold(): void
    {
        $realm = new GameNotesRealm()->setMaxCoin(2400);

        // null >= 0 would be true
        $this->assertFalse($realm->reachesThreshold(0));
        $this->assertFalse($realm->reachesThreshold(1500));
        $this->assertFalse($realm->isWithinThreshold(self::accountData(0)));
    }

    public function testIsWithinThresholdUsesTheThresholdOfTheProfile(): void
    {
        $realm = new GameNotesRealm()->setCurrentCoin(2000)->setMaxCoin(2400);

        $this->assertTrue($realm->isWithinThreshold(self::accountData(1500)));
        $this->assertFalse($realm->isWithinThreshold(self::accountData(2100)));
        $this->assertFalse($realm->isWithinThreshold(self::accountData(-1)));
    }
}
