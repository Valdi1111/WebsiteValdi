<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<WeekliesReminderProfileMessage>
 */
#[AsMessageHandler]
class WeekliesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    private WeekliesPresenter $weekliesPresenter;

    #[Required]
    public function setWeekliesPresenter(WeekliesPresenter $weekliesPresenter): void
    {
        $this->weekliesPresenter = $weekliesPresenter;
    }

    public function __invoke(WeekliesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasWeekliesInterface) {
            return;
        }

        $weeklies = $gameService->getWeekliesData($accountData);
        if ($weeklies->allDone()) {
            return;
        }

        $notification = $this->weekliesPresenter->present(
            $accountData->getGameProfile(),
            $weeklies,
            'Weeklies Reminder',
            "Don't Forget to Do Your Weeklies!"
        );

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
