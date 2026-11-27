<?php

namespace App\CoreBundle\Service\Chat\Telegram;

use App\CoreBundle\Exception\InvalidChatCommandLineException;
use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Service\Chat\ChatCommandLineParser;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\ChatExecutor;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place an update of the Telegram bot (https://core.telegram.org/bots/api#update) is handled: from the webhook
 * (TelegramRemoteEventConsumer, already in a core_chat worker) and from telegram:updates:poll (development). Both are
 * background processes, so everything runs here at once: a command through ChatDispatcher::runCommand(), a button press
 * through ChatDispatcher::prepareAction() then ChatExecutor::handleAction().
 *
 * - message with a text: "/name@Bot sub args" is a command, answered with a new message; any other text gets a hint;
 * - callback_query (button): answered first (answerCallbackQuery stops the spinner of the client, the work can take
 *   seconds), then the press edits its message; a refusal is only the text of that answer, a failure a new message.
 *
 * Only private chats: Telegram has no ephemeral messages, so in a group everyone would read the answers. The update
 * comes from Telegram but its shape is not trusted: anything unexpected is ignored.
 *
 * Never throws: a failure is logged by class and code only (the messages can hold the bot token), and the chat, when
 * there is one, gets the generic error.
 */
class TelegramUpdateHandler
{
    /** The updates the bot asks for (telegram:webhook:set, telegram:updates:poll): the others are never handled */
    public const array ALLOWED_UPDATES = ['message', 'callback_query'];
    public const string NOT_A_COMMAND = 'Send /help to see the commands.';
    /** Telegram sends /start when a user opens the bot for the first time: answered as /help */
    public const string START_COMMAND = 'start';
    public const string HELP_COMMAND = 'help';
    /** Telegram's limit for the text of answerCallbackQuery */
    public const int MAX_CALLBACK_TEXT_LENGTH = 200;
    /** Polling only: older updates are the backlog of a poller that was stopped, not something the user waits for */
    public const int MAX_POLLED_AGE_SECONDS = 5 * 60;
    public const string DEFAULT_LOCALE = 'en';

    private const string PRIVATE_CHAT = 'private';

    /** Username of the bot (getMe), read on the first "/name@Bot" and kept: it never changes while the process runs */
    private ?string $botUsername = null;

    public function __construct(
        private readonly ChatCommandLineParser $parser,
        private readonly ChatDispatcher $dispatcher,
        private readonly ChatExecutor $executor,
        private readonly ChatReplyDelivery $delivery,
        private readonly TelegramBotApiClient $botApi,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $update
     * @param bool         $polled from telegram:updates:poll: an update older than MAX_POLLED_AGE_SECONDS is skipped,
     *                             so that starting the poller does not replay the backlog. The webhook handles every
     *                             update Telegram sends
     */
    public function handle(array $update, bool $polled = false): void
    {
        $updateId = is_int($update['update_id'] ?? null) ? $update['update_id'] : null;
        try {
            if (is_array($update['message'] ?? null)) {
                $this->handleMessage($update['message'], $updateId, $polled);
            } elseif (is_array($update['callback_query'] ?? null)) {
                $this->handleCallbackQuery($update['callback_query'], $updateId, $polled);
            }
        } catch (\Throwable $e) {
            // Only what the two handlers let through, which catch everything themselves: there is no chat to answer
            $this->logFailure($e, $updateId);
        }
    }

    /**
     * @param array<mixed> $message
     */
    private function handleMessage(array $message, ?int $updateId, bool $polled): void
    {
        $chatId = self::privateChatId($message['chat'] ?? null);
        $userId = self::userId($message['from'] ?? null);
        $text = $message['text'] ?? null;
        // Stickers, photos, joins...: nothing to answer
        if ($chatId === null || $userId === null || !is_string($text)) {
            return;
        }
        if ($polled && $this->isTooOld($message, $updateId)) {
            return;
        }

        try {
            $reply = $this->answerText($text, $userId, self::locale($message['from']));
            if ($reply !== null) {
                // Without a message id: a new message
                $this->delivery->send(TelegramTarget::create($chatId), $reply, ChatReplyMode::Replace);
            }
        } catch (\Throwable $e) {
            $this->logFailure($e, $updateId);
            $this->delivery->send(TelegramTarget::create($chatId), ChatReply::error(), ChatReplyMode::FollowUp);
        }
    }

    /**
     * @return ChatReply|null the answer, or null when the command is for another bot
     */
    private function answerText(string $text, string $userId, string $locale): ?ChatReply
    {
        if (!str_starts_with(ltrim($text), '/')) {
            return ChatReply::text(self::NOT_A_COMMAND);
        }

        // Before parsing: a command for another bot gets no answer, not even an error about its arguments
        $botName = self::botNameOf($text);
        if ($botName !== null && !$this->isThisBot($botName)) {
            return null;
        }

        try {
            $line = $this->parser->parse($text);
        } catch (InvalidChatCommandLineException $e) {
            return ChatReply::error($e->getMessage());
        }

        // The payload of a "/start <payload>" deep link is not an argument of /help
        $result = $line->command === self::START_COMMAND
            ? $this->dispatcher->runCommand(ChatPlatform::Telegram, $userId, self::HELP_COMMAND, null, [], $locale)
            : $this->dispatcher->runCommand(ChatPlatform::Telegram, $userId, $line->command, $line->subcommand, $line->arguments, $locale);

        return $result->reply;
    }

    /**
     * @param array<mixed> $callback
     */
    private function handleCallbackQuery(array $callback, ?int $updateId, bool $polled): void
    {
        $callbackId = $callback['id'] ?? null;
        if (!is_string($callbackId) || $callbackId === '') {
            return;
        }

        $answered = false;
        $chatId = null;
        try {
            $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
            $chatId = self::privateChatId($message['chat'] ?? null);
            $userId = self::userId($callback['from'] ?? null);
            $messageId = $message['message_id'] ?? null;
            $data = $callback['data'] ?? null;
            if ($chatId === null || $userId === null || !is_int($messageId) || !is_string($data) || $data === '') {
                // Still answered, or the client keeps spinning
                $answered = true;
                $this->answerCallbackQuery($callbackId);

                return;
            }
            // A callback has no date of its own: the one of its message (or of its last edit) is the only age there is
            if ($polled && $this->isTooOld($message, $updateId)) {
                return;
            }

            $target = TelegramTarget::create($chatId, (string) $messageId);
            $prepared = $this->dispatcher->prepareAction(ChatPlatform::Telegram, $userId, $data, self::locale($callback['from']), $target);
            if ($prepared instanceof ChatReply) {
                // The refusal is a notice on the button: the message stays as it is
                $answered = true;
                $this->answerCallbackQuery($callbackId, self::callbackText($prepared));

                return;
            }

            // Before the work, which can take seconds (API calls of a Refresh)
            $answered = true;
            $this->answerCallbackQuery($callbackId);

            $result = $this->executor->handleAction($prepared);
            // A failure is sent apart, so the message keeps its data and its buttons
            $this->delivery->send($target, $result->reply, $result->failed ? ChatReplyMode::FollowUp : ChatReplyMode::Replace);
        } catch (\Throwable $e) {
            $this->logFailure($e, $updateId);
            if (!$answered) {
                $this->answerCallbackQuery($callbackId);
            }
            if ($chatId !== null) {
                $this->delivery->send(TelegramTarget::create($chatId), ChatReply::error(), ChatReplyMode::FollowUp);
            }
        }
    }

    /**
     * Never throws: Telegram refuses an answer to a query older than a few seconds (a press queued for long, a
     * replayed update), and the press must run all the same.
     */
    private function answerCallbackQuery(string $callbackId, ?string $text = null): void
    {
        $parameters = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $parameters += ['text' => $text, 'show_alert' => false];
        }

        try {
            $this->botApi->call('answerCallbackQuery', $parameters);
        } catch (TelegramBotApiException $e) {
            $this->logger->warning('Telegram callback query not answered: {error}', ['error' => $e::class, 'status' => $e->statusCode]);
        }
    }

    /**
     * Whether "@name" of "/command@name" is this bot. While getMe fails, any name is: ignoring the command of a user
     * would be worse than answering one meant for another bot, which in a private chat with this bot is rare.
     */
    private function isThisBot(string $name): bool
    {
        if ($this->botUsername === null) {
            try {
                $me = $this->botApi->call('getMe');
                $this->botUsername = is_array($me) && is_string($me['username'] ?? null) ? $me['username'] : null;
            } catch (TelegramBotApiException $e) {
                // Not kept: the next command tries again
                $this->logger->warning('Telegram bot username not read: {error}', ['error' => $e::class, 'status' => $e->statusCode]);
            }
        }

        // Usernames are case-insensitive
        return $this->botUsername === null || strcasecmp($name, $this->botUsername) === 0;
    }

    /**
     * @param array<mixed> $message
     */
    private function isTooOld(array $message, ?int $updateId): bool
    {
        $date = max(is_int($message['date'] ?? null) ? $message['date'] : 0, is_int($message['edit_date'] ?? null) ? $message['edit_date'] : 0);
        if ($this->clock->now()->getTimestamp() - $date <= self::MAX_POLLED_AGE_SECONDS) {
            return false;
        }

        $this->logger->info('Telegram update {update_id} skipped: older than {seconds} seconds', ['update_id' => $updateId, 'seconds' => self::MAX_POLLED_AGE_SECONDS]);

        return true;
    }

    private function logFailure(\Throwable $e, ?int $updateId): void
    {
        $this->logger->error('Telegram update {update_id} not handled: {error}', ['update_id' => $updateId, 'error' => $e::class, 'code' => $e->getCode()]);
    }

    /**
     * The id of a private chat, as the targets keep it; null for a group, a channel or a malformed chat.
     */
    private static function privateChatId(mixed $chat): ?string
    {
        return is_array($chat) && ($chat['type'] ?? null) === self::PRIVATE_CHAT && is_int($chat['id'] ?? null) ? (string) $chat['id'] : null;
    }

    /**
     * The Telegram user id, the one of the link on the profile page (ChatUserResolver).
     */
    private static function userId(mixed $from): ?string
    {
        return is_array($from) && is_int($from['id'] ?? null) ? (string) $from['id'] : null;
    }

    /**
     * The language of the user's Telegram app ("it", "pt-br"), as a two-letter code.
     */
    private static function locale(mixed $from): string
    {
        $code = is_array($from) ? ($from['language_code'] ?? null) : null;

        return is_string($code) && preg_match('/^[a-z]{2}/i', $code) === 1 ? strtolower(substr($code, 0, 2)) : self::DEFAULT_LOCALE;
    }

    /**
     * "Bot" of "/help@Bot args", or null without a suffix.
     */
    private static function botNameOf(string $text): ?string
    {
        return preg_match('/^\s*\/[^\s@]*@(\S+)/u', $text, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The refusal as the notice of answerCallbackQuery: plain text without the warning sign of ChatReply::error(), cut
     * to what Telegram accepts.
     */
    private static function callbackText(ChatReply $refusal): string
    {
        $text = trim($refusal->text ?? '') !== '' ? trim((string) $refusal->text) : ChatReply::ERROR;
        $warning = (string) ChatReply::error('')->text;
        if (str_starts_with($text, $warning)) {
            $text = substr($text, strlen($warning));
        }

        return mb_strlen($text) > self::MAX_CALLBACK_TEXT_LENGTH ? mb_substr($text, 0, self::MAX_CALLBACK_TEXT_LENGTH - 1).'…' : $text;
    }
}
