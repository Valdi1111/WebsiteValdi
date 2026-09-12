<?php

namespace App\CoreBundle\Model\Notification;

interface NotifiableUserInterface
{
    public function getDiscordUserId(): ?string;

    public function getTelegramChatId(): ?string;
}