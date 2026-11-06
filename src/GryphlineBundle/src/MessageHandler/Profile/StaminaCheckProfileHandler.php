<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Model\Game\GryphlineGameInterface;
use App\CoreBundle\Model\Game\HasStaminaInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
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
        GryphlineGameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasStaminaInterface) {
            return;
        }

        $stamina = $gameService->getStaminaData($accountData);
        if (!$stamina->isWithinThreshold($accountData)) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, 'Stamina Reminder')
            ->setDescription($stamina->isFull() ? 'Your stamina is full!' : 'Your stamina is within the set threshold!')
            ->addField('Stamina', "{$stamina->getCurrentStamina()}/{$stamina->getMaxStamina()}", icon: '🔋', inline: true)
            ->addField('Recovery Time', $gameService->getSkportUtils()->formatTime($stamina->getStaminaRecoveryTime()), icon: '🕒', inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
