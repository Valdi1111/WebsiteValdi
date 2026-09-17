<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Message\WeekliesReminderMessage;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<WeekliesReminderMessage>
 */
#[AsMessageHandler]
class WeekliesReminderMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(WeekliesReminderMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasWeekliesInterface) {
            return;
        }

        $weeklies = $gameService->getWeekliesData($accountData);
        if ($weeklies->allDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Weeklies Reminder")
            ->setDescription("Don't Forget to Do Your Weeklies!");

        foreach ($weeklies as $weekly) {
            $notification->addField($weekly->getName(), $weekly->getFormattedOutput(), icon: "📊", inline: true);
        }

        $this->sendNotificationToAccountData($accountData, $notification);
    }

}