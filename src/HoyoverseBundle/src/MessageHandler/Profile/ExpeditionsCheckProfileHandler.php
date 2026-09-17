<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\ExpeditionsCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<ExpeditionsCHeckProfileMessage>
 */
#[AsMessageHandler]
class ExpeditionsCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(ExpeditionsCHeckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasExpeditionsInterface) {
            return;
        }

        $expeditions = $gameService->getExpeditionsData($accountData);
        if (!$expeditions->allDone()) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Expeditions Reminder")
            ->setDescription("All expeditions are completed!");

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}