<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Embeds shared by the check-in handlers (auto and missed).
 *
 * @mixin AbstractProfileTaskMessageHandler
 */
trait HoyolabCheckInNotificationTrait
{
    private GameProfileEmbedFactory $embedFactory;

    #[Required]
    public function setEmbedFactory(GameProfileEmbedFactory $embedFactory): void
    {
        $this->embedFactory = $embedFactory;
    }

    /**
     * The sign request met a captcha: nothing was claimed, and the player has to check in by hand.
     *
     * The handler returns normally after this: a Messenger retry would only call sign again within a minute and meet
     * the captcha again, while each extra attempt can raise the risk flag of the account.
     */
    private function notifyCheckInRisk(RuntimeAccountData $accountData): void
    {
        $notification = $this->embedFactory->create($accountData->getGameProfile(), 'Daily Check-In Failed')
            ->setDescription("HoYoLAB asked for a captcha, so today's reward was NOT claimed. Please check in by hand on HoYoLAB.")
            ->setColor(0xED4245);

        $this->sendNotificationToAccountData($accountData, $notification);
    }
}
