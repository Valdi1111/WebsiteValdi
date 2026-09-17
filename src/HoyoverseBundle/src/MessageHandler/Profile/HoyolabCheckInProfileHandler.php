<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<HoyolabCheckInProfileMessage>
 */
#[AsMessageHandler]
class HoyolabCheckInProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(HoyolabCheckInProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasHoyolabCheckInInterface) {
            return;
        }

        $checkInResult = $gameService->checkIn($accountData);

        $notification = $this->createNotificationWithAccountData($accountData, "Daily Check-In")
            ->setDescription($checkInResult->getResult())
            ->addField("Today's Reward", $checkInResult->getAwardData()->getName(), icon: "🎁", inline: true)
            ->addField("Total Sign-ins", $checkInResult->getTotalSignDay(), icon: "📅", inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}