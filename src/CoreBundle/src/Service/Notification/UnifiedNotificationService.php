<?php

namespace App\CoreBundle\Service\Notification;

use App\CoreBundle\Model\Notification\NotifiableUserInterface;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use Symfony\Component\Notifier\Bridge\Discord\DiscordOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

class UnifiedNotificationService
{
    public const string PLATFORM_DISCORD = 'discord';
    public const string PLATFORM_TELEGRAM = 'telegram';

    public function __construct(
        private readonly ChatterInterface        $chatter,
        private readonly DiscordEmbedTransformer $discordTransformer,
        private readonly TelegramHtmlRenderer    $telegramRenderer,
    )
    {
    }

    /**
     * Invia la notifica ad un utente sulle piattaforme specificate.
     *
     * @param NotifiableUserInterface $user L'utente target (da sessione web o estratto da DB nello scheduler)
     * @param UniversalEmbed $embed L'embed universale
     * @param string[] $notificationPlatforms Lista piattaforme abilitate per questo invio
     */
    public function sendToUser(
        NotifiableUserInterface $user,
        UniversalEmbed          $embed,
        array                   $notificationPlatforms = [self::PLATFORM_DISCORD, self::PLATFORM_TELEGRAM]
    ): void
    {
        $platforms = array_map('strtolower', $notificationPlatforms);

        // Controllo e invio Discord
        if (in_array(self::PLATFORM_DISCORD, $platforms, true)) {
            $discordUserId = $user->getDiscordUserId();
            if (!empty($discordUserId)) {
                $this->sendToDiscord($discordUserId, $embed);
            }
        }

        // Controllo e invio Telegram
        if (in_array(self::PLATFORM_TELEGRAM, $platforms, true)) {
            $telegramChatId = $user->getTelegramChatId();
            if (!empty($telegramChatId)) {
                $this->sendToTelegram($telegramChatId, $embed);
            }
        }
    }

    public function sendToDiscord(string $recipientId, UniversalEmbed $embed): void
    {
        $discordOptions = new DiscordOptions()
            ->addEmbed($this->discordTransformer->transform($embed));

        $message = new ChatMessage(sprintf('<@%s>', $recipientId))
            ->transport(self::PLATFORM_DISCORD)
            ->options($discordOptions);

        $this->chatter->send($message);
    }

    public function sendToTelegram(string $chatId, UniversalEmbed $embed, bool $respectInlineLayout = false): void
    {
        $html = $this->telegramRenderer->render($embed, $respectInlineLayout);

        $telegramOptions = new TelegramOptions()
            ->chatId($chatId)
            ->parseMode(TelegramOptions::PARSE_MODE_HTML)
            ->disableWebPagePreview(false);

        $message = new ChatMessage($html)
            ->transport(self::PLATFORM_TELEGRAM)
            ->options($telegramOptions);

        $this->chatter->send($message);
    }
}