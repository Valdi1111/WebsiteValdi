<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<StaminaCheckProfileMessage>
 */
#[AsMessageHandler]
class StaminaCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(StaminaCheckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasStaminaInterface) {
            return;
        }

        $stamina = $gameService->getStaminaData($accountData);
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