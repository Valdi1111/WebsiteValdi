<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\Model\Game\GryphlineGameInterface;
use App\CoreBundle\Model\Game\HasWeekliesInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
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
        GryphlineGameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasWeekliesInterface) {
            return;
        }

        $weeklies = $gameService->getWeekliesData($accountData);
        if ($weeklies->allDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, 'Weeklies Reminder')
            ->setDescription("Don't Forget to Do Your Weeklies!");

        foreach ($weeklies as $weekly) {
            $notification->addField($weekly->getName(), $weekly->getFormattedOutput(), icon: '📊', inline: true);
        }

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
