<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\DailiesReminderMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Notes\HasDailiesNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<DailiesReminderMessage>
 */
#[AsMessageHandler]
class DailiesReminderMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(DailiesReminderMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasDailiesInterface) {
            return;
        }

        $notes = $gameService->getNotes($accountData);
        if (!$notes instanceof HasDailiesNotes) {
            return;
        }

        $dailies = $notes->getDailiesData();
        $stamina = $notes->getStaminaData();

        if ($dailies->isDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Dailies Reminder")
            ->setDescription("Don't Forget to Do Your Dailies!")
            ->addField("Completed Dailies", "{$dailies->getCurrentTask()}/{$dailies->getMaxTask()}", icon: "📅", inline: true)
            ->addField("Stamina", "{$stamina->getCurrentStamina()}/{$stamina->getMaxStamina()} ({$gameService->getHoyolabUtils()->formatTime($stamina->getStaminaRecoveryTime())})", icon: "🔋", inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }

}