<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\HoyolabMissedCheckInProfileMessage;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<HoyolabMissedCheckInProfileMessage>
 */
#[AsMessageHandler]
class HoyolabMissedCheckInProfileHandler extends AbstractProfileTaskMessageHandler
{
    private GameProfileEmbedFactory $embedFactory;

    #[Required]
    public function setEmbedFactory(GameProfileEmbedFactory $embedFactory): void
    {
        $this->embedFactory = $embedFactory;
    }

    public function __invoke(HoyolabMissedCheckInProfileMessage $message): void
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

        if (!$checkInResult->isAlreadySignedIn()) {
            $notification = $this->embedFactory->create($accountData->getGameProfile(), "Daily Check-In")
                ->setDescription($checkInResult->getResult())
                ->addField("Today's Reward", "{$checkInResult->getAwardData()->getName()}", icon: "🎁", inline: true)
                ->addField("Total Sign-ins", $checkInResult->getTotalSignDay(), icon: "📅", inline: true);

            $this->sendNotificationToAccountData($accountData, $notification);
        }
    }
}