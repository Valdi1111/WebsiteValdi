<?php

namespace App\CoreBundle\Service\Telegram;

use App\CoreBundle\Exception\TelegramBotException;
use App\CoreBundle\Exception\TelegramMessagesNotAllowedException;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Messages of the bot of TELEGRAM_DSN sent at once, through the chatter transports, where the notifications
 * (UnifiedNotificationService) go through Messenger: the caller learns whether Telegram accepted the message.
 */
class TelegramBot
{
    public function __construct(
        #[Autowire(service: 'chatter.transports')]
        private readonly TransportInterface $transports,
    ) {
    }

    /**
     * @param string $text plain text; the transport escapes it for MarkdownV2
     *
     * @throws TelegramMessagesNotAllowedException when the user does not let the bot message them
     * @throws TelegramBotException                when the message could not be sent for another reason
     */
    public function sendMessage(string $chatId, string $text): void
    {
        $message = (new ChatMessage($text))
            ->transport(UnifiedNotificationService::PLATFORM_TELEGRAM)
            ->options((new TelegramOptions())->chatId($chatId));

        try {
            $this->transports->send($message);
        } catch (TransportExceptionInterface|HttpClientExceptionInterface $e) {
            if (self::isNotAllowed($e)) {
                throw new TelegramMessagesNotAllowedException($e);
            }

            throw new TelegramBotException('Could not send the Telegram message: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * "Bad Request: chat not found" when the user never started the bot nor allowed it at login, 403 "Forbidden: bot
     * was blocked by the user" when blocked.
     */
    private static function isNotAllowed(\Throwable $e): bool
    {
        if (!$e instanceof TransportException) {
            return false;
        }
        try {
            $status = $e->getResponse()->getStatusCode();
        } catch (HttpClientExceptionInterface) {
            // Telegram not reached
            return false;
        }

        return $status === 403 || ($status === 400 && str_contains($e->getMessage(), 'chat not found'));
    }
}
