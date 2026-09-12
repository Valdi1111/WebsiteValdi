<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\StaminaCheckMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\Notes\HasStaminaNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<StaminaCheckMessage>
 */
#[AsMessageHandler]
class StaminaCheckMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(StaminaCheckMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasStaminaInterface) {
            return;
        }

        $notes = $gameService->getNotes($accountData);
        if (!$notes instanceof HasStaminaNotes) {
            return;
        }

        $stamina = $notes->getStaminaData();
        if (!$stamina->isWithinThreshold($accountData)) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Stamina Reminder")
            ->setDescription($stamina->isFull() ? "Your stamina is full!" : "Your stamina is within the set threshold!")
            ->addField("Stamina", "{$stamina->getCurrentStamina()}/{$stamina->getMaxStamina()}", icon: "🔋", inline: true)
            ->addField("Recovery Time", $gameService->getHoyolabUtils()->formatTime($stamina->getStaminaRecoveryTime()), icon: "🕒", inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }

}