<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Message\Profile\MimoCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @implements AbstractProfileTaskMessageHandler<MimoCheckProfileMessage>
 */
#[AsMessageHandler]
class MimoCheckProfileHandler extends AbstractProfileTaskMessageHandler
{
    public function __invoke(MimoCheckProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasMimoInterface) {
            return;
        }

        // TODO
    }
}