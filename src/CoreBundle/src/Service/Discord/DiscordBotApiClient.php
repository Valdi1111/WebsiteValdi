<?php

namespace App\CoreBundle\Service\Discord;

use App\CoreBundle\Exception\DiscordBotApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Discord API as the bot of the application (DISCORD_BOT_TOKEN, sent by the scoped client discord.bot.client),
 * only to register the slash commands (chat:commands:sync). The answers to the interactions do not need the bot: they
 * go through DiscordReplySender, authenticated by the interaction token.
 *
 * The application is the bot's own (GET applications/@me), so DISCORD_CLIENT_ID is not needed here.
 */
class DiscordBotApiClient
{
    /** Discord's error details kept in an exception message, enough to find the refused field */
    private const int MAX_ERROR_DETAILS = 1000;

    public function __construct(
        #[Target('discord.bot.client')]
        private readonly HttpClientInterface $client,
        #[Autowire(env: 'DISCORD_BOT_TOKEN')]
        #[\SensitiveParameter]
        private readonly string $botToken,
    ) {
    }

    /**
     * False without DISCORD_BOT_TOKEN: the commands are then not synced.
     */
    public function isConfigured(): bool
    {
        return $this->botToken !== '';
    }

    /**
     * @throws DiscordBotApiException
     */
    public function getApplicationId(): string
    {
        $id = $this->request('GET', 'applications/@me')['id'] ?? null;
        if (!is_string($id) || !ctype_digit($id)) {
            throw new DiscordBotApiException('Discord answered GET applications/@me without an application id.');
        }

        return $id;
    }

    /**
     * Bulk overwrite: the global commands of the application become exactly $commands, any other is deleted.
     *
     * @param list<array<string, mixed>> $commands application command objects (DiscordCommandPayloadBuilder)
     * @return int how many global commands the application now has
     *
     * @throws DiscordBotApiException
     */
    public function putGlobalCommands(string $applicationId, array $commands): int
    {
        if (!ctype_digit($applicationId)) {
            throw new \InvalidArgumentException('A Discord application id is a snowflake.');
        }

        return count($this->request('PUT', sprintf('applications/%s/commands', $applicationId), ['json' => $commands]));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed> the decoded JSON answer
     *
     * @throws DiscordBotApiException with the status and Discord's error, never the HttpClient message nor the token
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $label = $method.' '.$path;
        try {
            $response = $this->client->request($method, $path, $options);
            $status = $response->getStatusCode();
            if ($status >= 300) {
                throw new DiscordBotApiException(sprintf('Discord refused %s: HTTP %d%s.', $label, $status, self::describeError($response)), $status);
            }

            return $response->toArray();
        } catch (TransportExceptionInterface) {
            throw new DiscordBotApiException(sprintf('Discord could not be reached for %s.', $label));
        } catch (HttpClientExceptionInterface) {
            throw new DiscordBotApiException(sprintf('Discord answered %s without JSON.', $label), $status ?? null);
        }
    }

    /**
     * The JSON error of Discord (e.g. 50035 "Invalid Form Body" with the refused fields of the payload), which tells
     * what to fix; it echoes the request, never the token of the header.
     */
    private static function describeError(ResponseInterface $response): string
    {
        try {
            $error = $response->toArray(false);
        } catch (HttpClientExceptionInterface) {
            return '';
        }

        $details = json_encode(
            array_intersect_key($error, ['code' => true, 'message' => true, 'errors' => true]),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $details === false || $details === '[]' ? '' : ' '.mb_strimwidth($details, 0, self::MAX_ERROR_DETAILS, '…');
    }
}
