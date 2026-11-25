<?php

namespace App\Tests\GamesBundle\Model\GameNotes;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use PHPUnit\Framework\TestCase;

class GameNotesStaminaTest extends TestCase
{
    public function testIsFull(): void
    {
        $stamina = new GameNotesStamina();
        $this->assertFalse($stamina->isFull());

        $stamina->setCurrentStamina(100)->setMaxStamina(200);
        $this->assertFalse($stamina->isFull());

        $stamina->setCurrentStamina(200)->setMaxStamina(200);
        $this->assertTrue($stamina->isFull());

        $stamina->setCurrentStamina(210)->setMaxStamina(200);
        $this->assertTrue($stamina->isFull());
    }

    public function testIsWithinThresholdWithNegativeOrNullThresholdDelegatesToIsFull(): void
    {
        $stamina = new GameNotesStamina();
        $stamina->setCurrentStamina(180)->setMaxStamina(200);

        $profile = $this->createStub(GameProfileInterface::class);
        $profile->method('getStaminaThreshold')->willReturn(-1);

        $accountData = $this->createStub(RuntimeAccountDataInterface::class);
        $accountData->method('getGameProfile')->willReturn($profile);

        // Not full yet -> false
        $this->assertFalse($stamina->isWithinThreshold($accountData));

        // Now full -> true
        $stamina->setCurrentStamina(200);
        $this->assertTrue($stamina->isWithinThreshold($accountData));

        // When threshold is null -> delegates to isFull()
        $profileNull = $this->createStub(GameProfileInterface::class);
        $profileNull->method('getStaminaThreshold')->willReturn(null);
        $accountDataNull = $this->createStub(RuntimeAccountDataInterface::class);
        $accountDataNull->method('getGameProfile')->willReturn($profileNull);

        $this->assertTrue($stamina->isWithinThreshold($accountDataNull));
    }

    public function testIsWithinThresholdWithExplicitThreshold(): void
    {
        $stamina = new GameNotesStamina();
        $stamina->setCurrentStamina(150)->setMaxStamina(200);

        $profile = $this->createStub(GameProfileInterface::class);
        $profile->method('getStaminaThreshold')->willReturn(160);

        $accountData = $this->createStub(RuntimeAccountDataInterface::class);
        $accountData->method('getGameProfile')->willReturn($profile);

        // 150 < 160 -> false
        $this->assertFalse($stamina->isWithinThreshold($accountData));

        // 160 >= 160 -> true
        $stamina->setCurrentStamina(160);
        $this->assertTrue($stamina->isWithinThreshold($accountData));

        // 180 >= 160 -> true
        $stamina->setCurrentStamina(180);
        $this->assertTrue($stamina->isWithinThreshold($accountData));
    }

    public function testSecondsUntilFullPreferTheFullInstant(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 10:00:00');
        $stamina = new GameNotesStamina()->setStaminaRecoverTime(3600);
        $this->assertSame(3600, $stamina->getSecondsUntilFull($now), 'without an instant, the relative time');

        $stamina->setFullAt($now->modify('+1000 seconds'));
        $this->assertSame(1000, $stamina->getSecondsUntilFull($now));

        $stamina->setFullAt($now->modify('-10 seconds'));
        $this->assertSame(0, $stamina->getSecondsUntilFull($now), 'never negative once full');

        $this->assertNull(new GameNotesStamina()->getSecondsUntilFull($now));
    }

    public function testReachesThreshold(): void
    {
        $stamina = new GameNotesStamina()->setCurrentStamina(180)->setMaxStamina(200);

        $this->assertTrue($stamina->reachesThreshold(160));
        $this->assertFalse($stamina->reachesThreshold(190));
        $this->assertFalse($stamina->reachesThreshold(-1), 'negative: only when full');
        $this->assertFalse($stamina->reachesThreshold(null), 'null: only when full');
        $this->assertTrue($stamina->setCurrentStamina(200)->reachesThreshold(null));
    }

    public function testAnUnknownCurrentStaminaNeverReachesAThreshold(): void
    {
        $stamina = new GameNotesStamina()->setMaxStamina(200);

        foreach ([0, 160, -1, null] as $threshold) {
            $profile = $this->createStub(GameProfileInterface::class);
            $profile->method('getStaminaThreshold')->willReturn($threshold);
            $accountData = $this->createStub(RuntimeAccountDataInterface::class);
            $accountData->method('getGameProfile')->willReturn($profile);

            // null >= 0 would be true
            $this->assertFalse($stamina->isWithinThreshold($accountData), sprintf('Threshold %s', var_export($threshold, true)));
        }
    }
}
