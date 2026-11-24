<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Exception\TelegramOAuthException;
use App\CoreBundle\Service\Telegram\TelegramOAuthClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * TelegramOAuthClient against the OpenID Connect endpoints of Telegram, served by FakeTelegramApi with id tokens
 * signed by a test key: the authorization request, the code exchange and the checks of the id token.
 */
class TelegramOAuthClientTest extends TestCase
{
    private const string REDIRECT_URI = 'https://valdi.test:8443/profile/telegram/callback';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-24 21:00:00');
    }

    private function api(): FakeTelegramApi
    {
        return new FakeTelegramApi($this->clock);
    }

    private function client(FakeTelegramApi $api, mixed ...$arguments): TelegramOAuthClient
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnMap([[TelegramOAuthClient::CALLBACK_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_PATH, '/profile/telegram/callback']]);

        // Trailing slash on purpose: DEFAULT_URI may have one
        return $api->oauthClient($urlGenerator, 'https://valdi.test:8443/', ...$arguments);
    }

    public function testIsConfiguredWithClientIdAndSecretOfTheBotOfTheNotifications(): void
    {
        $api = $this->api();

        $this->assertTrue($this->client($api)->isConfigured());
        $this->assertFalse($this->client($api, clientId: '')->isConfigured());
        $this->assertFalse($this->client($api, clientSecret: '')->isConfigured());
    }

    public function testIsNotConfiguredWhenTheNotificationsUseAnotherBot(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('TELEGRAM_DSN'));

        $this->assertFalse($this->client($this->api(), botDsn: 'telegram://111111111:other-secret@default', logger: $logger)->isConfigured());
    }

    public function testIsNotConfiguredWithoutAReadableTelegramDsn(): void
    {
        $this->assertFalse($this->client($this->api(), botDsn: 'not a dsn')->isConfigured());
    }

    public function testAuthorizationUrlAsksForTheUserIdAndTheBotAccessWithPkce(): void
    {
        $verifier = TelegramOAuthClient::createCodeVerifier();

        $url = $this->client($this->api())->getAuthorizationUrl('the-state', $verifier);

        $this->assertStringStartsWith('https://oauth.telegram.org/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => FakeTelegramApi::BOT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid profile telegram:bot_access',
            'state' => 'the-state',
            'code_challenge' => FakeTelegramApi::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], $query);
        // RFC 7636: 43 to 128 characters of the unreserved set
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
    }

    public function testFetchUserExchangesTheCodeWithPkceAndReadsTheIdToken(): void
    {
        $api = $this->api();

        $user = $this->client($api)->fetchUser('the-code', 'the-verifier');

        $this->assertSame([FakeTelegramApi::USER_ID, 'nelly', 'Nelly', FakeTelegramApi::PICTURE], [$user->id, $user->username, $user->name, $user->picture]);
        $this->assertSame([
            'POST https://oauth.telegram.org/token',
            'GET https://oauth.telegram.org/.well-known/jwks.json',
        ], $api->requestedUrls());
        $this->assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => FakeTelegramApi::BOT_ID,
            'code_verifier' => 'the-verifier',
        ], $api->body(0));
        $this->assertSame('Authorization: Basic '.base64_encode(FakeTelegramApi::BOT_ID.':'.FakeTelegramApi::CLIENT_SECRET), $api->authorization(0));
    }

    public function testTheUserIdIsTheIdClaimAlsoAsANumber(): void
    {
        $api = $this->api();
        $api->claims = ['id' => 155735202, 'preferred_username' => null, 'name' => str_repeat('N', 100)];

        $user = $this->client($api)->fetchUser('the-code', 'the-verifier');

        $this->assertSame(['155735202', null, str_repeat('N', 64)], [$user->id, $user->username, $user->name]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusablePictures(): iterable
    {
        yield 'no picture, as without one shown' => [null];
        yield 'not https' => ['http://t.me/i/userpic/320/picture.jpg'];
        yield 'a script' => ['javascript:alert(1)'];
        yield 'not a URL' => ['picture.jpg'];
        yield 'too long for the column' => ['https://t.me/i/userpic/320/'.str_repeat('a', 230).'.jpg'];
        yield 'not a string' => [['https://t.me/i/userpic/320/picture.jpg']];
    }

    #[DataProvider('unusablePictures')]
    public function testAPictureThatCannotBeShownIsLeftOut(mixed $picture): void
    {
        $api = $this->api();
        $api->claims = ['picture' => $picture];

        $user = $this->client($api)->fetchUser('the-code', 'the-verifier');

        $this->assertNull($user->picture);
        // The account is linked all the same
        $this->assertSame(FakeTelegramApi::USER_ID, $user->id);
    }

    public function testAnIdTokenStillValidWithinTheAllowedClockDifferenceIsAccepted(): void
    {
        $api = $this->api();
        // Issued 30 seconds ahead of our clock, expired 50 seconds ago by it
        $api->claims = ['iat' => $this->clock->now()->getTimestamp() + 30, 'exp' => $this->clock->now()->getTimestamp() - 50];

        $this->assertSame(FakeTelegramApi::USER_ID, $this->client($api)->fetchUser('the-code', 'the-verifier')->id);
    }

    /**
     * @return iterable<string, array{0: \Closure(FakeTelegramApi, int): void}>
     */
    public static function invalidIdTokens(): iterable
    {
        yield 'signature of another payload' => [static function (FakeTelegramApi $api): void {
            $api->tamper = static function (string $token): string {
                [$header, , $signature] = explode('.', $token);

                return $header.'.'.FakeTelegramApi::base64Url(json_encode(['id' => '1', 'aud' => FakeTelegramApi::BOT_ID])).'.'.$signature;
            };
        }];
        yield 'no signature, "none" algorithm' => [static function (FakeTelegramApi $api): void {
            $api->tamper = static function (string $token): string {
                [, $payload] = explode('.', $token);

                return FakeTelegramApi::base64Url(json_encode(['alg' => 'none', 'kid' => FakeTelegramApi::KEY_ID])).'.'.$payload.'.';
            };
        }];
        yield 'HMAC algorithm' => [static function (FakeTelegramApi $api): void {
            $api->header = ['alg' => 'HS256'];
        }];
        yield 'unknown key' => [static function (FakeTelegramApi $api): void {
            $api->header = ['kid' => 'other-key'];
        }];
        yield 'no key id' => [static function (FakeTelegramApi $api): void {
            $api->header = ['kid' => null];
        }];
        yield 'another bot' => [static function (FakeTelegramApi $api): void {
            $api->claims = ['aud' => '111111111'];
        }];
        yield 'another issuer' => [static function (FakeTelegramApi $api): void {
            $api->claims = ['iss' => 'https://evil.example'];
        }];
        yield 'expired' => [static function (FakeTelegramApi $api, int $now): void {
            $api->claims = ['iat' => $now - 200, 'exp' => $now - 61];
        }];
        yield 'issued in the future' => [static function (FakeTelegramApi $api, int $now): void {
            $api->claims = ['iat' => $now + 61, 'exp' => $now + 91];
        }];
        yield 'without expiration' => [static function (FakeTelegramApi $api): void {
            $api->claims = ['exp' => null];
        }];
        yield 'without the id claim, as without the "profile" scope' => [static function (FakeTelegramApi $api): void {
            $api->claims = ['id' => null];
        }];
        yield 'an id that is not a user id' => [static function (FakeTelegramApi $api): void {
            $api->claims = ['id' => '-100123'];
        }];
        yield 'not a token' => [static function (FakeTelegramApi $api): void {
            $api->tamper = static fn (): string => 'not-a-token';
        }];
    }

    /**
     * @param \Closure(FakeTelegramApi, int): void $breakToken
     */
    #[DataProvider('invalidIdTokens')]
    public function testAnInvalidIdTokenIsRefused(\Closure $breakToken): void
    {
        $api = $this->api();
        $breakToken($api, $this->clock->now()->getTimestamp());

        $this->expectException(TelegramOAuthException::class);
        $this->client($api)->fetchUser('the-code', 'the-verifier');
    }

    public function testACodeTelegramRefusesFailsWithItsError(): void
    {
        $api = $this->api();
        $api->tokenError = 'invalid_grant';

        $this->expectException(TelegramOAuthException::class);
        $this->expectExceptionMessage('without an id token: invalid_grant');
        $this->client($api)->fetchUser('expired-code', 'the-verifier');
    }

    public function testATokenEndpointErrorFails(): void
    {
        $api = $this->api();
        $api->tokenStatus = 500;

        $this->expectException(TelegramOAuthException::class);
        $this->expectExceptionMessage('Could not exchange the Telegram authorization code');
        $this->client($api)->fetchUser('the-code', 'the-verifier');
    }
}
