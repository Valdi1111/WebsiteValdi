<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\CoreBundle\Model\Game\HasDailiesInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
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
    ): void {
        if (!$gameService instanceof HasDailiesInterface) {
            return;
        }

        $dailies = $gameService->getDailiesData($accountData);
        if ($dailies->allDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, 'Dailies Reminder')
            ->setDescription("Don't Forget to Do Your Dailies!");

        foreach ($dailies as $daily) {
            $notification->addField($daily->getName(), $daily->getFormattedOutput(), icon: '📅', inline: true);
        }

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
