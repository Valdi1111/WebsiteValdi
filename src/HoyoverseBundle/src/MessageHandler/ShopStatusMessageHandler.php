<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\ShopStatusMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Notes\HasShopStatusNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<ShopStatusMessage>
 */
#[AsMessageHandler]
class ShopStatusMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(ShopStatusMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasShopStatusInterface) {
            return;
        }

        $notes = $gameService->getNotes($accountData);
        if (!$notes instanceof HasShopStatusNotes) {
            return;
        }

        $shopStatus = $notes->getVhsSaleState();
        if (!$shopStatus->isDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Shop Status Reminder")
            ->setDescription("Your shop has finished selling videos!");

        $this->sendNotificationToAccountData($accountData, $notification);
    }

}