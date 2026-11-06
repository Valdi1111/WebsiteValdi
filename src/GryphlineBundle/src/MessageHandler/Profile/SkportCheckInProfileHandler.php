<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Model\Game\GryphlineGameInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<SkportCheckInProfileMessage>
 */
#[AsMessageHandler]
class SkportCheckInProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(SkportCheckInProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GryphlineGameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasCheckInInterface) {
            return;
        }

        $checkInResult = $gameService->checkIn($accountData);

        $notification = $this->createNotificationWithAccountData($accountData, 'Daily Check-In')
            ->setDescription($checkInResult->getResult())
            ->addField("Today's Reward", $checkInResult->getAwardData()?->getName() ?? 'Item', icon: '🎁', inline: true)
            ->addField('Total Sign-ins', (string) $checkInResult->getTotalSignDay(), icon: '📅', inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
