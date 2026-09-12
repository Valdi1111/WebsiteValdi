<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @extends AbstractTaskMessageHandler<UpdateCookieMessage>
 */
#[AsMessageHandler]
class UpdateCookieMessageHandler extends AbstractTaskMessageHandler
{

    public function __invoke(UpdateCookieMessage $message): void
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

        // TODO avrebbe senso non usare AbstractTaskMessageHandler perchè tanto processiamo gli account e non i gameProfile
    }

}