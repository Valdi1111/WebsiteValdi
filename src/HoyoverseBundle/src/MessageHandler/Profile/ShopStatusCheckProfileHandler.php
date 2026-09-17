<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<ShopStatusCHeckProfileMessage>
 */
#[AsMessageHandler]
class ShopStatusCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(ShopStatusCHeckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasShopStatusInterface) {
            return;
        }

        $shopStatus = $gameService->getShopStatusData($accountData);
        if (!$shopStatus->isDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Shop Status Reminder")
            ->setDescription("Your shop has finished selling videos!");

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}