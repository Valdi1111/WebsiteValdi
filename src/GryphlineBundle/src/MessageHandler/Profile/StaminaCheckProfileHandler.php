<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<StaminaCheckProfileMessage>
 */
#[AsMessageHandler]
class StaminaCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    private StaminaPresenter $staminaPresenter;

    #[Required]
    public function setStaminaPresenter(StaminaPresenter $staminaPresenter): void
    {
        $this->staminaPresenter = $staminaPresenter;
    }

    public function __invoke(StaminaCheckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasStaminaInterface) {
            return;
        }

        $stamina = $gameService->getStaminaData($accountData);
        if (!$stamina->isWithinThreshold($accountData)) {
            return;
        }

        $notification = $this->staminaPresenter->present(
            $accountData->getGameProfile(),
            $stamina,
            'Stamina Reminder',
            $stamina->isFull() ? 'Your stamina is full!' : 'Your stamina is within the set threshold!'
        );

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
