<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\RealmCurrencyMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\HasRealmInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Notes\HasRealmNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<RealmCurrencyMessage>
 */
#[AsMessageHandler]
class RealmCurrencyMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(RealmCurrencyMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasRealmInterface) {
            return;
        }

        $notes = $gameService->getNotes($accountData);
        if (!$notes instanceof HasRealmNotes) {
            return;
        }

        $realm = $notes->getRealmData();
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