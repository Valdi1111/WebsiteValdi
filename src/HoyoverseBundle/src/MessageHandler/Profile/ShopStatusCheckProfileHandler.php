<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Presenter\ShopStatusPresenter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<ShopStatusCHeckProfileMessage>
 */
#[AsMessageHandler]
class ShopStatusCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    private ShopStatusPresenter $shopStatusPresenter;

    #[Required]
    public function setShopStatusPresenter(ShopStatusPresenter $shopStatusPresenter): void
    {
        $this->shopStatusPresenter = $shopStatusPresenter;
    }

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

        $notification = $this->shopStatusPresenter->present(
            $accountData->getGameProfile(),
            $shopStatus,
            "Shop Status Reminder",
            "Your shop has finished selling videos!"
        );

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
