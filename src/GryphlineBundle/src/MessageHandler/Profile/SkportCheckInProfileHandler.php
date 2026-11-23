<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<SkportCheckInProfileMessage>
 */
#[AsMessageHandler]
class SkportCheckInProfileHandler extends AbstractProfileTaskMessageHandler
{
    private GameProfileEmbedFactory $embedFactory;

    #[Required]
    public function setEmbedFactory(GameProfileEmbedFactory $embedFactory): void
    {
        $this->embedFactory = $embedFactory;
    }

    public function __invoke(SkportCheckInProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasCheckInInterface) {
            return;
        }

        $checkInResult = $gameService->checkIn($accountData);

        $notification = $this->embedFactory->create($accountData->getGameProfile(), 'Daily Check-In')
            ->setDescription($checkInResult->getResult())
            ->addField("Today's Reward", $checkInResult->getAwardData()?->getName() ?? 'Item', icon: '🎁', inline: true)
            ->addField('Total Sign-ins', (string) $checkInResult->getTotalSignDay(), icon: '📅', inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
