<?php

namespace App\CoreBundle\Service\Chat\Discord;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
 * When Discord refuses the message (4xx, e.g. a limit the renderer missed), it is sent once more as text only, so the
 * deferred answer does not stay "thinking…" forever. The URL holds the token, a credential for 15 minutes: neither
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

        $status = $this->request($method, $path, $this->renderer->render($reply) + $flags);
        if ($status >= 400 && $status < 500) {
            $this->logger?->warning('Discord refused a chat answer (HTTP {status}): sending it as text only.', ['status' => $status, 'mode' => $mode->value]);
            $status = $this->request($method, $path, $this->renderer->renderText($reply) + $flags);
        }

        if ($status >= 300) {
            throw new ChatReplyNotSentException(sprintf('Discord refused the answer: HTTP %d.', $status), $status);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return int the HTTP status
     */
    private function request(string $method, string $path, array $payload): int
    {
        try {
            // The status is where a transport error surfaces: the request itself is lazy
            return $this->client->request($method, $path, ['json' => $payload])->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // Not chained: its message quotes the URL, and loggers print the previous exceptions too
            $this->logger?->warning('Discord could not be reached for a chat answer: {error}', ['error' => $e::class, 'method' => $method]);

            throw new ChatReplyNotSentException('Discord could not be reached for the answer.');
        }
    }
}
