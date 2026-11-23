<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<DailiesReminderProfileMessage>
 */
#[AsMessageHandler]
class DailiesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    private DailiesPresenter $dailiesPresenter;

    #[Required]
    public function setDailiesPresenter(DailiesPresenter $dailiesPresenter): void
    {
        $this->dailiesPresenter = $dailiesPresenter;
    }

    public function __invoke(DailiesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasDailiesInterface) {
            return;
        }

        $dailies = $gameService->getDailiesData($accountData);
        if ($dailies->allDone()) {
            return;
        }

        $notification = $this->dailiesPresenter->present(
            $accountData->getGameProfile(),
            $dailies,
            'Dailies Reminder',
            "Don't Forget to Do Your Dailies!"
        );

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
