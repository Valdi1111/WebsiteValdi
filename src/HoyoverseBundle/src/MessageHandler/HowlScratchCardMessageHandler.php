<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\HowlScratchCardMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\HasHowlScratchCardInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Notes\HasHowlScratchCardNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<HowlScratchCardMessage>
 */
#[AsMessageHandler]
class HowlScratchCardMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(HowlScratchCardMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasHowlScratchCardInterface) {
            return;
        }

        $notes = $gameService->getNotes($accountData);
        if (!$notes instanceof HasHowlScratchCardNotes) {
            return;
        }

        $cardSign = $notes->getCardSign();
        if ($cardSign->isDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Howl's News Stand Reminder")
            ->setDescription("You haven't scratched the card at Howl's News Stand yet!");

        $this->sendNotificationToAccountData($accountData, $notification);
    }

}