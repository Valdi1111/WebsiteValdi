<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\ExpeditionCheckMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<ExpeditionCheckMessage>
 */
#[AsMessageHandler]
class ExpeditionCheckMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(ExpeditionCheckMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
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