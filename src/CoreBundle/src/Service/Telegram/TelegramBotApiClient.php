<?php

namespace App\CoreBundle\Service\Telegram;

use App\CoreBundle\Exception\TelegramBotApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Notifier\Exception\InvalidArgumentException as InvalidDsnException;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Bot API of the bot of TELEGRAM_DSN, for what the notifier transport does not do (chat answers in HTML with
 * buttons, callback answers, webhook, polling, command menu). The token is read from TELEGRAM_DSN, its only place, as
 * the notifier reads it ("telegram://<id>:<secret>@default").
 *
 * The token is in the path of every call: errors carry the method, the status and Telegram's description, never the
 * URL nor the HttpClient messages (TelegramBotTokenLogProcessor masks the HttpClient logs).
 */
class TelegramBotApiClient
{
    public function __construct(
        #[Target('telegram.bot.client')]
        private readonly HttpClientInterface $client,
        #[Autowire(env: 'TELEGRAM_DSN')]
        #[\SensitiveParameter]
        private readonly string $botDsn,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null;
    }

    /**
     * Calls a Bot API method and gives its "result".
     *
     * @param array<string, mixed> $parameters sent as JSON, as they are (no filtering: false and 0 are values)
     * @param float|null           $timeout    for long polling (getUpdates), above its own timeout
     *
     * @throws TelegramBotApiException
     */
    public function call(string $method, array $parameters = [], ?float $timeout = null): mixed
    {
        $options = ['json' => (object) $parameters];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        return $this->request($method, $options);
    }

    /**
     * Calls a Bot API method that uploads files (sendPhoto, sendDocument...), as multipart/form-data, and gives its
     * "result".
     *
     * @param array<string, scalar|array<mixed>|null> $parameters the other parameters: arrays (reply_markup) are sent
     *                                                            as JSON, booleans as "true"/"false", null is left out
     * @param array<string, DataPart>                 $files      the files, by parameter name (e.g. "photo")
     *
     * @throws TelegramBotApiException
     */
    public function callMultipart(string $method, array $parameters, array $files): mixed
    {
        $fields = [];
        foreach ($parameters as $name => $value) {
            $fields[$name] = match (true) {
                $value === null => null,
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }
        $form = new FormDataPart(array_filter($fields, static fn (?string $value): bool => $value !== null) + $files);

        return $this->request($method, ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()]);
    }

    /**
     * @param array<string, mixed> $options of the HttpClient request
     *
     * @throws TelegramBotApiException
     */
    private function request(string $method, array $options): mixed
    {
        $token = $this->token() ?? throw new TelegramBotApiException(sprintf('Telegram %s not called: TELEGRAM_DSN has no bot token.', $method), $method);

        try {
            // "./": without it the ":" of the token would make "bot<id>" read as a URL scheme
            $response = $this->client->request('POST', sprintf('./bot%s/%s', $token, $method), $options);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpClientExceptionInterface) {
            // Not chained: its message quotes the URL, and the loggers print the previous exceptions too
            throw new TelegramBotApiException(sprintf('Telegram could not be reached for %s.', $method), $method);
        }

        if ($status !== 200 || ($body['ok'] ?? false) !== true) {
            $description = is_string($body['description'] ?? null) ? $body['description'] : null;

            throw new TelegramBotApiException(sprintf('Telegram refused %s: HTTP %d%s.', $method, $status, $description !== null ? ', '.$description : ''), $method, $status, $description);
        }

        return $body['result'] ?? null;
    }

    private function token(): ?string
    {
        if (trim($this->botDsn) === '') {
            return null;
        }

        try {
            $dsn = new Dsn($this->botDsn);
        } catch (InvalidDsnException) {
            return null;
        }

        return $dsn->getUser() !== null && $dsn->getPassword() !== null ? $dsn->getUser().':'.$dsn->getPassword() : null;
    }
}
