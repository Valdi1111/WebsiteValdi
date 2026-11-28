<?php

namespace App\CoreBundle\Service\Chat\Telegram;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Exception\TelegramBotApiException;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Service\Telegram\TelegramBotApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Answers a Telegram chat from the worker, through the Bot API client (not the notifier transport, which escapes the
 * text for MarkdownV2 and drops falsy options):
 *
 * - Replace with a message (the one of the pressed button) edits it (editMessageText); "message is not modified", an
 *   identical Refresh, is a success. Any other refused edit (message too old, deleted) sends the answer as a new
 *   message instead;
 * - Replace without a message (a typed command) and FollowUp send a new message (sendMessage).
 *
 * An answer with an image is always a new message, with its text as caption: a photo (sendPhoto) when Telegram keeps
 * it as it is, else a document (sendDocument) at full size, since Telegram shrinks a photo to 2560 pixels on its longer
 * side and a tall card blurs. When Telegram refuses the file, the text goes alone and says so.
 *
 * When Telegram cannot parse the HTML, the call is made once more as plain text (TelegramReplyRenderer::renderPlain()).
 * An unreachable Telegram is not retried: the edit may have gone through. Errors carry the HTTP status only.
 */
class TelegramReplySender implements ChatReplySenderInterface
{
    /** Longer side Telegram keeps without shrinking a photo (seen live, not documented) */
    public const int PHOTO_MAX_SIDE = 2560;
    /** Limits of sendPhoto: width + height, ratio between the sides, size */
    public const int PHOTO_MAX_DIMENSIONS = 10000;
    public const int PHOTO_MAX_RATIO = 20;
    public const int PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly TelegramBotApiClient $botApi,
        private readonly TelegramReplyRenderer $renderer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(ChatPlatform $platform): bool
    {
        return $platform === ChatPlatform::Telegram;
    }

    public function send(ChatReplyTarget $target, ChatReply $reply, ChatReplyMode $mode): void
    {
        $chatId = (string) TelegramTarget::chatId($target);
        if (preg_match('/^-?\d+$/D', $chatId) !== 1) {
            throw new ChatReplyNotSentException('Telegram answer not sent: the target has no chat.');
        }
        if ($reply->image !== null) {
            $this->sendImage((int) $chatId, $reply, $reply->image);

            return;
        }

        $messageId = $mode === ChatReplyMode::Replace ? (string) TelegramTarget::messageId($target) : '';

        // Once Telegram could not parse the HTML, the new message goes as plain text straight away
        $plain = false;
        try {
            if (ctype_digit($messageId)) {
                try {
                    $this->call('editMessageText', ['chat_id' => (int) $chatId, 'message_id' => (int) $messageId], $reply, $plain);

                    return;
                } catch (TelegramBotApiException $e) {
                    if ($e->isMessageNotModified()) {
                        return;
                    }
                    if ($e->statusCode !== 400) {
                        throw $e;
                    }
                    $this->logger?->warning('Telegram refused to edit a chat answer (HTTP {status}: {description}): sending it as a new message.', self::context($e));
                }
            }

            $this->call('sendMessage', ['chat_id' => (int) $chatId], $reply, $plain);
        } catch (TelegramBotApiException $e) {
            $this->logger?->warning('Telegram did not take a chat answer (HTTP {status}: {description}).', self::context($e));

            // Not chained: the status is all the worker logs
            throw new ChatReplyNotSentException($e->statusCode === null ? 'Telegram could not be reached for the answer.' : sprintf('Telegram refused the answer: HTTP %d.', $e->statusCode), $e->statusCode);
        }
    }

    /**
     * @throws ChatReplyNotSentException
     */
    private function sendImage(int $chatId, ChatReply $reply, ChatImage $image): void
    {
        $asPhoto = self::fitsPhoto($image);
        [$method, $field] = $asPhoto ? ['sendPhoto', 'photo'] : ['sendDocument', 'document'];
        // Without it, a WebP document becomes a sticker
        $extra = $asPhoto ? [] : ['disable_content_type_detection' => true];
        $file = static fn (): DataPart => new DataPart($image->content, $image->filename, $image->contentType);

        try {
            try {
                $this->botApi->callMultipart($method, ['chat_id' => $chatId] + $this->renderer->renderCaption($reply) + $extra, [$field => $file()]);

                return;
            } catch (TelegramBotApiException $e) {
                if ($e->statusCode !== 400 || !str_contains(strtolower((string) $e->description), "can't parse entities")) {
                    throw $e;
                }
                $this->logger?->warning('Telegram could not parse a chat caption (HTTP {status}: {description}): sending it as plain text.', self::context($e));
            }
            $this->botApi->callMultipart($method, ['chat_id' => $chatId] + $this->renderer->renderPlainCaption($reply) + $extra, [$field => $file()]);
        } catch (TelegramBotApiException $e) {
            // Unreachable, a server error or a rate limit: the text alone would fare no better
            if ($e->statusCode === null || $e->statusCode < 400 || $e->statusCode >= 500 || $e->statusCode === 429) {
                $this->logger?->warning('Telegram did not take a chat answer (HTTP {status}: {description}).', self::context($e));

                throw new ChatReplyNotSentException($e->statusCode === null ? 'Telegram could not be reached for the answer.' : sprintf('Telegram refused the answer: HTTP %d.', $e->statusCode), $e->statusCode);
            }
            // The file was refused (too large...): the text goes alone, and says so
            $this->logger?->warning('Telegram refused a chat image (HTTP {status}: {description}): sending the text only.', self::context($e));
            $text = trim((string) $reply->text);
            $this->send(
                TelegramTarget::create((string) $chatId),
                ChatReply::text($text === '' ? ChatReply::IMAGE_NOT_SENT : $text."\n".ChatReply::IMAGE_NOT_SENT),
                ChatReplyMode::FollowUp,
            );
        }
    }

    /**
     * Whether Telegram takes the image as a photo without shrinking it.
     */
    private static function fitsPhoto(ChatImage $image): bool
    {
        $long = max($image->width, $image->height);
        $short = max(1, min($image->width, $image->height));

        return $long <= self::PHOTO_MAX_SIDE
            && $image->width + $image->height <= self::PHOTO_MAX_DIMENSIONS
            && $long / $short <= self::PHOTO_MAX_RATIO
            && $image->size() <= self::PHOTO_MAX_BYTES;
    }

    /**
     * @param array<string, int> $ids
     * @param bool               $plain whether to send the plain text; set when Telegram could not parse the HTML
     * @throws TelegramBotApiException
     */
    private function call(string $method, array $ids, ChatReply $reply, bool &$plain): void
    {
        if (!$plain) {
            try {
                $this->botApi->call($method, $ids + $this->renderer->render($reply));

                return;
            } catch (TelegramBotApiException $e) {
                if ($e->statusCode !== 400 || !str_contains(strtolower((string) $e->description), "can't parse entities")) {
                    throw $e;
                }
            }

            // The HTML of the notifier's Markdown is not always Telegram's (e.g. a <br />): the content still goes
            $this->logger?->warning('Telegram could not parse a chat answer (HTTP {status}: {description}): sending it as plain text.', self::context($e));
            $plain = true;
        }

        $this->botApi->call($method, $ids + $this->renderer->renderPlain($reply));
    }

    /**
     * @return array{method: string, status: int|null, description: string|null}
     */
    private static function context(TelegramBotApiException $e): array
    {
        return ['method' => $e->method, 'status' => $e->statusCode, 'description' => $e->description];
    }
}
