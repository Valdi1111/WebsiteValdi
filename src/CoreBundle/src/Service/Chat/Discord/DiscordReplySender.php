<?php

namespace App\CoreBundle\Service\Chat\Discord;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Answers a Discord interaction from the worker, through its webhook
 * (https://discord.com/developers/docs/interactions/receiving-and-responding#followup-messages): authenticated by the
 * interaction token in the URL, so on the unauthenticated discord.client, without the bot token.
 *
 * - Replace edits the original response: the deferred "thinking…" of a command, or the message of the pressed button;
 * - FollowUp posts a new message only the user sees.
 *
 * An answer with an image goes as multipart/form-data: the message as payload_json, the file as files[0]
 * (https://discord.com/developers/docs/reference#uploading-files).
 *
 * When Discord refuses the message (4xx, e.g. a limit the renderer missed, a file too large), it is sent once more as
 * text only, which says when the image was left out, so the deferred answer does not stay "thinking…" forever. The URL holds the token, a credential for 15 minutes: neither
 * the URL nor the HttpClient messages (which quote it) reach an exception or a log, only the HTTP status.
 */
class DiscordReplySender implements ChatReplySenderInterface
{
    public const int FLAG_EPHEMERAL = 64;

    public function __construct(
        #[Target('discord.client')]
        private readonly HttpClientInterface $client,
        private readonly DiscordReplyRenderer $renderer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(ChatPlatform $platform): bool
    {
        return $platform === ChatPlatform::Discord;
    }

    public function send(ChatReplyTarget $target, ChatReply $reply, ChatReplyMode $mode): void
    {
        $applicationId = (string) $target->get('application_id');
        $token = (string) $target->get('token');
        if (!ctype_digit($applicationId) || $token === '') {
            throw new ChatReplyNotSentException('Discord answer not sent: the target has no application id or token.');
        }

        $webhook = sprintf('webhooks/%s/%s', $applicationId, rawurlencode($token));
        [$method, $path, $flags] = match ($mode) {
            ChatReplyMode::Replace => ['PATCH', $webhook.'/messages/@original', []],
            // A new message, for the user alone
            ChatReplyMode::FollowUp => ['POST', $webhook, ['flags' => self::FLAG_EPHEMERAL]],
        };

        $payload = $this->renderer->render($reply) + $flags;
        $status = $this->request($method, $path, $reply->image !== null ? self::multipart($payload, $reply->image) : ['json' => $payload]);
        if ($status >= 400 && $status < 500) {
            $this->logger?->warning('Discord refused a chat answer (HTTP {status}): sending it as text only.', ['status' => $status, 'mode' => $mode->value]);
            $status = $this->request($method, $path, ['json' => $this->renderer->renderText($reply) + $flags]);
        }

        if ($status >= 300) {
            throw new ChatReplyNotSentException(sprintf('Discord refused the answer: HTTP %d.', $status), $status);
        }
    }

    /**
     * @param array<string, mixed> $payload the message
     *
     * @return array{headers: list<string>, body: iterable<string>} HttpClient options
     */
    private static function multipart(array $payload, ChatImage $image): array
    {
        $form = new FormDataPart([
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'files[0]' => new DataPart($image->content, $image->filename, $image->contentType),
        ]);

        return ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()];
    }

    /**
     * @param array<string, mixed> $options HttpClient options: the JSON message, or the multipart body
     * @return int the HTTP status
     */
    private function request(string $method, string $path, array $options): int
    {
        try {
            // The status is where a transport error surfaces: the request itself is lazy
            return $this->client->request($method, $path, $options)->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // Not chained: its message quotes the URL, and loggers print the previous exceptions too
            $this->logger?->warning('Discord could not be reached for a chat answer: {error}', ['error' => $e::class, 'method' => $method]);

            throw new ChatReplyNotSentException('Discord could not be reached for the answer.');
        }
    }
}
