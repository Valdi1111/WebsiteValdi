<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\MimoTaskMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<MimoTaskMessage>
 */
#[AsMessageHandler]
class MimoTaskMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(MimoTaskMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        // TODO process profile
    }

}