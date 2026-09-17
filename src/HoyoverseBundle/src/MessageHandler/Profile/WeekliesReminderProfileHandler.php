<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<WeekliesReminderProfileMessage>
 */
#[AsMessageHandler]
class WeekliesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(WeekliesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
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