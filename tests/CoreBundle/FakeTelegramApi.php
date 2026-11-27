<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Service\Telegram\TelegramBot;
use App\CoreBundle\Service\Telegram\TelegramOAuthClient;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Transport\Transports;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Telegram as the site sees it, served through MockHttpClient with the requests recorded: the OpenID Connect endpoints
 * (code exchange, keys), with id tokens really signed by a key generated for the tests, and the Bot API methods of the
 * bot of BOT_TOKEN (sendMessage of the notifier and of the chat bot, editMessageText, answerCallbackQuery, getUpdates,
 * the webhook and the command menu).
 */
final class FakeTelegramApi
{
    public const string OAUTH_BASE_URI = 'https://oauth.telegram.org/';
    public const string BOT_ID = '9030271366';
    public const string BOT_TOKEN = self::BOT_ID.':bot-secret';
    public const string CLIENT_SECRET = 'client-secret';
    public const string USER_ID = '155735202';
    /** Shaped as Telegram gives it: a t.me address that redirects to its CDN */
    public const string PICTURE = 'https://t.me/i/userpic/320/test-picture-of-nelly.jpg';
    public const string KEY_ID = 'test-key';

    /** @var array{private: string, n: string, e: string}|null generated once: RSA key generation is slow */
    private static ?array $key = null;

    public const string BOT_API = 'https://api.telegram.org/';
    public const string BOT_USERNAME = 'valdi_test_bot';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * Answers of the next Bot API calls, by method, in order: [status, body]; a method without one answers as Telegram
     * does when it accepts the call. sendMessage keeps $sendStatus / $sendError when it has none here.
     *
     * @var array<string, list<array{int, array<string, mixed>}>>
     */
    public array $botAnswers = [];

    /** @var list<list<array<string, mixed>>> updates of the next getUpdates calls, one list per call */
    public array $updates = [];

    /** URL of getWebhookInfo, "" when no webhook is set */
    public string $webhookUrl = '';

    private int $nextMessageId = 100;

    /**
     * @param array<string, mixed> $claims      claims of the id token, over the valid ones of claims()
     * @param array<string, mixed> $header      header of the id token, over the valid one
     * @param \Closure|null        $tamper      changes the id token before it is answered
     * @param int                  $tokenStatus status of the code exchange
     * @param string|null          $tokenError  error of a code exchange Telegram refuses, e.g. "invalid_grant"
     * @param int                  $sendStatus  status of sendMessage: 200, or a Bot API error
     * @param string|null          $sendError   description of the Bot API error
     */
    public function __construct(
        private readonly ClockInterface $clock,
        public array $claims = [],
        public array $header = [],
        public ?\Closure $tamper = null,
        public int $tokenStatus = 200,
        public ?string $tokenError = null,
        public int $sendStatus = 200,
        public ?string $sendError = null,
    ) {
    }

    /**
     * @param string|null $baseUri the one of the scoped client "telegram.oauth.client" for TelegramOAuthClient
     */
    public function client(?string $baseUri = null): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return match ($method.' '.$url) {
                'POST '.self::OAUTH_BASE_URI.'token' => match (true) {
                    $this->tokenStatus !== 200 => self::json($this->tokenStatus, ['error' => 'server_error']),
                    // As the real Telegram answers a wrong code: HTTP 200 with an error
                    $this->tokenError !== null => self::json(200, ['error' => $this->tokenError]),
                    default => self::json(200, ['access_token' => 'access-token', 'id_token' => $this->idToken(), 'token_type' => 'Bearer', 'expires_in' => 30, 'scope' => 'openid profile']),
                },
                'GET '.self::OAUTH_BASE_URI.'.well-known/jwks.json' => self::json(200, ['keys' => [self::jwk()]]),
                'POST https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => ($this->botAnswers['sendMessage'] ?? []) !== []
                    ? self::json(...array_shift($this->botAnswers['sendMessage']))
                    : self::json($this->sendStatus, $this->sendStatus === 200
                        ? ['ok' => true, 'result' => ['message_id' => 1, 'date' => $this->clock->now()->getTimestamp(), 'chat' => ['id' => (int) self::USER_ID, 'type' => 'private'], 'text' => 'Welcome']]
                        : ['ok' => false, 'error_code' => $this->sendStatus, 'description' => $this->sendError ?? 'Internal Server Error']),
                default => str_starts_with($url, self::BOT_API.'bot'.self::BOT_TOKEN.'/') && $method === 'POST'
                    ? $this->botApi(substr($url, strlen(self::BOT_API.'bot'.self::BOT_TOKEN.'/')))
                    : new MockResponse('Not Found', ['http_code' => 404]),
            };
        }, $baseUri);
    }

    /**
     * The Bot API calls made, in order, with their JSON parameters.
     *
     * @return list<array{method: string, params: array<string, mixed>}>
     */
    public function botCalls(): array
    {
        $calls = [];
        $prefix = self::BOT_API.'bot'.self::BOT_TOKEN.'/';
        foreach ($this->requests as $request) {
            if (str_starts_with($request['url'], $prefix)) {
                $body = $request['options']['body'] ?? '';
                $calls[] = ['method' => substr($request['url'], strlen($prefix)), 'params' => is_string($body) && $body !== '' ? json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : []];
            }
        }

        return $calls;
    }

    private function botApi(string $method): MockResponse
    {
        if (($this->botAnswers[$method] ?? []) !== []) {
            return self::json(...array_shift($this->botAnswers[$method]));
        }

        $now = $this->clock->now()->getTimestamp();
        $chat = ['id' => (int) self::USER_ID, 'type' => 'private'];

        return self::json(200, ['ok' => true, 'result' => match ($method) {
            'getMe' => ['id' => (int) self::BOT_ID, 'is_bot' => true, 'first_name' => 'Valdi test', 'username' => self::BOT_USERNAME],
            'getUpdates' => array_shift($this->updates) ?? [],
            'editMessageText' => ['message_id' => 1, 'date' => $now, 'chat' => $chat, 'text' => ''],
            'getWebhookInfo' => ['url' => $this->webhookUrl, 'has_custom_certificate' => false, 'pending_update_count' => 0],
            'sendMessage' => ['message_id' => $this->nextMessageId++, 'date' => $now, 'chat' => $chat, 'text' => ''],
            default => true,
        }]);
    }

    public function oauthClient(
        UrlGeneratorInterface $urlGenerator,
        string $defaultUri,
        string $clientId = self::BOT_ID,
        string $clientSecret = self::CLIENT_SECRET,
        string $botDsn = 'telegram://'.self::BOT_TOKEN.'@default',
        ?LoggerInterface $logger = null,
    ): TelegramOAuthClient {
        return new TelegramOAuthClient(
            $this->client(self::OAUTH_BASE_URI),
            $urlGenerator,
            $this->clock,
            $clientId,
            $clientSecret,
            $botDsn,
            $defaultUri,
            $logger,
        );
    }

    public function bot(): TelegramBot
    {
        return new TelegramBot(new Transports(['telegram' => new TelegramTransport(self::BOT_TOKEN, null, $this->client())]));
    }

    /**
     * The claims of a valid id token at the current time of the clock, as Telegram gives them.
     *
     * @return array<string, mixed>
     */
    public function validClaims(): array
    {
        $now = $this->clock->now()->getTimestamp();

        return [
            'sub' => '1978301787236420384',
            'aud' => self::BOT_ID,
            'exp' => $now + 30,
            'iat' => $now,
            'iss' => TelegramOAuthClient::ISSUER,
            'name' => 'Nelly',
            'given_name' => 'Nelly',
            'family_name' => '',
            // Only when the user has a profile picture they show
            'picture' => self::PICTURE,
            'preferred_username' => 'nelly',
            'id' => self::USER_ID,
        ];
    }

    public function idToken(): string
    {
        $header = array_filter([...['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID], ...$this->header], static fn ($value): bool => $value !== null);
        $claims = array_filter([...$this->validClaims(), ...$this->claims], static fn ($value): bool => $value !== null);

        $input = self::base64Url(json_encode($header, \JSON_THROW_ON_ERROR)).'.'.self::base64Url(json_encode($claims, \JSON_THROW_ON_ERROR));
        openssl_sign($input, $signature, self::key()['private'], \OPENSSL_ALGO_SHA256);
        $token = $input.'.'.self::base64Url($signature);

        return $this->tamper !== null ? ($this->tamper)($token) : $token;
    }

    /**
     * @return list<string> "METHOD url" of the requests, in order
     */
    public function requestedUrls(): array
    {
        return array_map(static fn (array $request): string => $request['method'].' '.$request['url'], $this->requests);
    }

    /**
     * @return array<string, mixed> the form-encoded or JSON body of a request
     */
    public function body(int $index): array
    {
        $body = (string) $this->requests[$index]['options']['body'];
        if (str_starts_with($body, '{')) {
            return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        }
        parse_str($body, $form);

        return $form;
    }

    public function authorization(int $index): ?string
    {
        return $this->requests[$index]['options']['normalized_headers']['authorization'][0] ?? null;
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private static function jwk(): array
    {
        // Without "use", like the keys of Telegram
        return ['kty' => 'RSA', 'alg' => 'RS256', 'kid' => self::KEY_ID, 'n' => self::key()['n'], 'e' => self::key()['e']];
    }

    /**
     * @return array{private: string, n: string, e: string}
     */
    private static function key(): array
    {
        if (self::$key === null) {
            $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            openssl_pkey_export($key, $private);
            $details = openssl_pkey_get_details($key);
            self::$key = ['private' => $private, 'n' => self::base64Url($details['rsa']['n']), 'e' => self::base64Url($details['rsa']['e'])];
        }

        return self::$key;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(int $status, array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
