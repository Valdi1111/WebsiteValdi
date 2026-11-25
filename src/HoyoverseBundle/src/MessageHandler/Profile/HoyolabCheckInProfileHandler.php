<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Exception\SignInRiskException;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
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
    use HoyolabCheckInNotificationTrait;

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

        try {
            $checkInResult = $gameService->checkIn($accountData);
        } catch (SignInRiskException) {
            $this->notifyCheckInRisk($accountData);

            return;
        }

        $notification = $this->embedFactory->create($accountData->getGameProfile(), "Daily Check-In")
            ->setDescription($checkInResult->getResult())
            ->addField("Today's Reward", $checkInResult->getAwardData()?->getName() ?? "Item", icon: "🎁", inline: true)
            ->addField("Total Sign-ins", $checkInResult->getTotalSignDay(), icon: "📅", inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}