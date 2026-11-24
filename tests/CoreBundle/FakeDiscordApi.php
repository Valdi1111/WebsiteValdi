<?php

namespace App\Tests\CoreBundle;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Discord API endpoints of the OAuth2 flow (code exchange, current user, revocation), served through
 * MockHttpClient, with the requests recorded.
 */
final class FakeDiscordApi
{
    public const string BASE_URI = 'https://discord.com/api/v10/';
    public const string ACCESS_TOKEN = 'discord-access-token';
    /** Example user of the Discord documentation; default avatar index (id >> 22) % 6 = 5 */
    public const string USER_ID = '80351110224678912';

    /** @var list<array{method: string, path: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $user user object answered by GET users/@me
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
    ) {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = str_starts_with($url, self::BASE_URI) ? substr($url, strlen(self::BASE_URI)) : $url;
            $this->requests[] = ['method' => $method, 'path' => $path, 'options' => $options];

            return match ($method.' '.$path) {
                'POST oauth2/token' => self::json($this->tokenStatus, $this->tokenStatus === 200
                    ? ['access_token' => self::ACCESS_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 604800, 'refresh_token' => 'refresh', 'scope' => 'identify']
                    : ['error' => 'invalid_grant', 'error_description' => 'Invalid "code" in request.']),
                'GET users/@me' => self::json($this->userStatus, $this->userStatus === 200 ? $this->user : ['message' => '401: Unauthorized', 'code' => 0]),
                'POST oauth2/token/revoke' => self::json($this->revokeStatus, []),
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

    public function authorization(int $index): ?string
    {
        return $this->requests[$index]['options']['normalized_headers']['authorization'][0] ?? null;
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
