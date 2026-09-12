<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\ShopStatusMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<ShopStatusMessage>
 */
#[AsMessageHandler]
class ShopStatusMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(ShopStatusMessage $message): void
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