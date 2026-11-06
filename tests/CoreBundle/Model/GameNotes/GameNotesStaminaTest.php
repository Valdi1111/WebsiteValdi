<?php

namespace App\Tests\CoreBundle\Model\GameNotes;

use App\CoreBundle\Model\Game\GameProfileInterface;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use App\CoreBundle\Model\GameNotes\GameNotesStamina;
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
}
