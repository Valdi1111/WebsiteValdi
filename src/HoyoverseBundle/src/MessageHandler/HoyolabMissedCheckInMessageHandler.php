<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\HoyolabMissedCheckInMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<HoyolabMissedCheckInMessage>
 */
#[AsMessageHandler]
class HoyolabMissedCheckInMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(HoyolabMissedCheckInMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasHoyolabCheckInInterface) {
            return;
        }

        $checkInResult = $gameService->checkIn($accountData);

        if (!$checkInResult->isAlreadySignedIn()) {
            $notification = $this->createNotificationWithAccountData($accountData, "Daily Check-In")
                ->setDescription($checkInResult->getResult())
                ->addField("Today's Reward", "{$checkInResult->getAwardData()->getName()}", icon: "🎁", inline: true)
                ->addField("Total Sign-ins", $checkInResult->getTotalSignDay(), icon: "📅", inline: true);

            $this->sendNotificationToAccountData($accountData, $notification);
        }
    }

}