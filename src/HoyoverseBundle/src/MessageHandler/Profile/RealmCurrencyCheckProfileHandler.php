<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<RealmCurrencyCheckProfileMessage>
 */
#[AsMessageHandler]
class RealmCurrencyCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(RealmCurrencyCheckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasRealmCurrencyInterface) {
            return;
        }

        $realm = $gameService->getRealmData($accountData);
        if (!$realm->isWithinThreshold($accountData)) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Realm Currency Reminder")
            ->setDescription($realm->isFull() ? "Your realm currency is full!" : "Your realm currency is within the set threshold!")
            ->addField("Realm Currency", "{$realm->getCurrentCoin()}/{$realm->getMaxCoin()}", icon: "💰", inline: true)
            ->addField("Recovery Time", $gameService->getHoyolabUtils()->formatTime($realm->getCoinRecoveryTime()), icon: "🕒", inline: true);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}