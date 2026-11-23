<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Presenter\RealmCurrencyPresenter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<RealmCurrencyCheckProfileMessage>
 */
#[AsMessageHandler]
class RealmCurrencyCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    private RealmCurrencyPresenter $realmCurrencyPresenter;

    #[Required]
    public function setRealmCurrencyPresenter(RealmCurrencyPresenter $realmCurrencyPresenter): void
    {
        $this->realmCurrencyPresenter = $realmCurrencyPresenter;
    }

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

        $notification = $this->realmCurrencyPresenter->present(
            $accountData->getGameProfile(),
            $realm,
            "Realm Currency Reminder",
            $realm->isFull() ? "Your realm currency is full!" : "Your realm currency is within the set threshold!"
        );

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
