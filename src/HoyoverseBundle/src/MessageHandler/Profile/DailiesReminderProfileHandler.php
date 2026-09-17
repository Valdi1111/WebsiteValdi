<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<DailiesReminderProfileMessage>
 */
#[AsMessageHandler]
class DailiesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(DailiesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasDailiesInterface) {
            return;
        }

        $dailies = $gameService->getDailiesData($accountData);
        if ($dailies->allDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Dailies Reminder")
            ->setDescription("Don't Forget to Do Your Dailies!");

        foreach ($dailies as $daily) {
            $notification->addField($daily->getName(), $daily->getFormattedOutput(), icon: "📅", inline: true);
        }

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}