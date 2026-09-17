<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\EndgamesReminderMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<EndgamesReminderMessage>
 */
#[AsMessageHandler]
class EndgamesReminderMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(EndgamesReminderMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasEndgamesInterface) {
            return;
        }

        // TODO
    }

}