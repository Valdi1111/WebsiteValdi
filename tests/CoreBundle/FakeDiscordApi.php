<?php

namespace App\Tests\CoreBundle;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Discord API endpoints the site calls, served through MockHttpClient, with the requests recorded:
 * - OAuth2 flow (code exchange, current user, revocation);
 * - answers to the interactions: "PATCH webhooks/{application}/{token}/messages/@original" and the follow-up
 *   "POST webhooks/{application}/{token}";
 * - bot: "GET applications/@me" and the bulk overwrite "PUT applications/{application}/commands".
 */
final class FakeDiscordApi
{
    public const string BASE_URI = 'https://discord.com/api/v10/';
    public const string ACCESS_TOKEN = 'discord-access-token';
    /** Example user of the Discord documentation; default avatar index (id >> 22) % 6 = 5 */
    public const string USER_ID = '80351110224678912';
    public const string APPLICATION_ID = '1180000000000000001';
    /** In a status queue: the request fails before any answer, with an error naming the whole URL */
    public const int TRANSPORT_ERROR = 0;

    /** @var list<array{method: string, path: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $user            user object answered by GET users/@me
     * @param list<int>            $webhookStatuses statuses of the next answers to the webhook requests, in order
     *                                              (TRANSPORT_ERROR for a transport error); 200 once used up
     */
    public function __construct(
        public array $user = [
            'id' => self::USER_ID,
            'username' => 'nelly',
            'discriminator' => '0',
            'global_name' => 'Nelly',
            'avatar' => '8342729096ea3675442027381ff50dfe',
        ],
        public int $tokenStatus = 200,
        public int $userStatus = 200,
        public int $revokeStatus = 200,
        public array $webhookStatuses = [],
        public int $applicationStatus = 200,
        public int $commandsStatus = 200,
    ) {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = str_starts_with($url, self::BASE_URI) ? substr($url, strlen(self::BASE_URI)) : $url;
            // A file upload comes as a callback the response reads after this one: kept as a string for the assertions
            if (($options['body'] ?? null) instanceof \Closure) {
                $options['body'] = MultipartBody::read($options['body']);
            }
            $this->requests[] = ['method' => $method, 'path' => $path, 'options' => $options];

            // Paths holding ids and tokens
            if (preg_match('#^webhooks/\d+/[^/]+(/messages/@original)?$#', $path, $matches) === 1) {
                $original = isset($matches[1]);
                if (($original && $method === 'PATCH') || (!$original && $method === 'POST')) {
                    return $this->webhookAnswer($url, $options);
                }
            }
            if (preg_match('#^applications/(\d+)/commands$#', $path) === 1 && $method === 'PUT') {
                return self::json($this->commandsStatus, $this->commandsStatus === 200
                    ? array_map(static fn (array $command): array => ['id' => '1190000000000000000', 'application_id' => self::APPLICATION_ID, 'version' => '1'] + $command, self::decode($options))
                    : ['code' => 50035, 'errors' => ['0' => ['name' => ['_errors' => [['code' => 'APPLICATION_COMMAND_INVALID_NAME', 'message' => 'Command name is invalid']]]]], 'message' => 'Invalid Form Body']);
            }

            return match ($method.' '.$path) {
                'POST oauth2/token' => self::json($this->tokenStatus, $this->tokenStatus === 200
                    ? ['access_token' => self::ACCESS_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 604800, 'refresh_token' => 'refresh', 'scope' => 'identify']
                    : ['error' => 'invalid_grant', 'error_description' => 'Invalid "code" in request.']),
                'GET users/@me' => self::json($this->userStatus, $this->userStatus === 200 ? $this->user : ['message' => '401: Unauthorized', 'code' => 0]),
                'POST oauth2/token/revoke' => self::json($this->revokeStatus, []),
                'GET applications/@me' => self::json($this->applicationStatus, $this->applicationStatus === 200
                    ? ['id' => self::APPLICATION_ID, 'name' => 'Valdi', 'bot_public' => false]
                    : ['message' => '401: Unauthorized', 'code' => 0]),
                default => new MockResponse('Not Found', ['http_code' => 404]),
            };
        }, self::BASE_URI);
    }

    /**
     * @return list<string> "METHOD path" of the requests, in order
     */
    public function requestedPaths(): array
    {
        return array_map(static fn (array $request): string => $request['method'].' '.$request['path'], $this->requests);
    }

    /**
     * @return array<string, string> the form-encoded body of a request
     */
    public function formBody(int $index): array
    {
        parse_str((string) $this->requests[$index]['options']['body'], $body);

        return $body;
    }

    /**
     * @return array<mixed> the JSON body of a request; of a file upload, its message (payload_json)
     */
    public function jsonBody(int $index): array
    {
        return self::decode($this->requests[$index]['options']);
    }

    /**
     * @return array<string, string|array{filename: string, contentType: string|null, content: string}> the parts of a
     *                                                                                                   file upload
     */
    public function multipartBody(int $index): array
    {
        $options = $this->requests[$index]['options'];

        return MultipartBody::parse((string) $options['body'], MultipartBody::contentType($options));
    }

    public function authorization(int $index): ?string
    {
        return $this->requests[$index]['options']['normalized_headers']['authorization'][0] ?? null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function webhookAnswer(string $url, array $options): MockResponse
    {
        $status = array_shift($this->webhookStatuses) ?? 200;
        if ($status === self::TRANSPORT_ERROR) {
            // As curl reports it: with the URL, which holds the interaction token
            return new MockResponse('', ['error' => sprintf('Could not resolve host: discord.com for "%s".', $url)]);
        }

        return self::json($status, $status < 300
            ? ['id' => '1200000000000000000', 'type' => 0] + self::decode($options)
            : ['code' => 50035, 'errors' => ['embeds' => ['_errors' => [['code' => 'BASE_TYPE_MAX_LENGTH', 'message' => 'Must be 6000 or fewer in length.']]]], 'message' => 'Invalid Form Body']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private static function decode(array $options): array
    {
        $body = (string) ($options['body'] ?? '');
        if (MultipartBody::isMultipart($options)) {
            // A message with files: the message is the payload_json part
            $body = (string) (MultipartBody::parse($body, MultipartBody::contentType($options))['payload_json'] ?? '');
        }

        return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
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
