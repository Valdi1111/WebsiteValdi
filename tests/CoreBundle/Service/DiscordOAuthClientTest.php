<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Exception\DiscordOAuthException;
use App\CoreBundle\Model\Discord\DiscordUser;
use App\CoreBundle\Service\Discord\DiscordCdn;
use App\CoreBundle\Service\Discord\DiscordOAuthClient;
use App\Tests\CoreBundle\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * DiscordOAuthClient against the Discord API endpoints of the OAuth2 flow, served by FakeDiscordApi,
 * plus the Discord user and avatar URL it gives.
 */
class DiscordOAuthClientTest extends TestCase
{
    private const string REDIRECT_URI = 'https://valdi.test:8443/profile/discord/callback';

    private function client(FakeDiscordApi $api, string $clientId = 'client-id', string $clientSecret = 'client-secret', ?LoggerInterface $logger = null): DiscordOAuthClient
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnMap([[DiscordOAuthClient::CALLBACK_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_PATH, '/profile/discord/callback']]);

        // Trailing slash on purpose: DEFAULT_URI may have one
        return new DiscordOAuthClient($api->client(), $urlGenerator, $clientId, $clientSecret, 'https://valdi.test:8443/', $logger);
    }

    public function testIsConfiguredOnlyWithClientIdAndSecret(): void
    {
        $api = new FakeDiscordApi();

        $this->assertTrue($this->client($api)->isConfigured());
        $this->assertFalse($this->client($api, clientId: '')->isConfigured());
        $this->assertFalse($this->client($api, clientSecret: '')->isConfigured());
    }

    public function testAuthorizationUrlAsksForIdentifyWithTheStateAndTheRedirectUriOfTheMainDomain(): void
    {
        $url = $this->client(new FakeDiscordApi())->getAuthorizationUrl('the-state');

        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'scope' => 'identify',
            'state' => 'the-state',
            'redirect_uri' => self::REDIRECT_URI,
            'prompt' => 'consent',
        ], $query);
    }

    public function testFetchUserExchangesTheCodeReadsTheUserAndRevokesTheToken(): void
    {
        $api = new FakeDiscordApi();

        $user = $this->client($api)->fetchUser('the-code');

        $this->assertEquals(new DiscordUser(FakeDiscordApi::USER_ID, 'nelly', 'Nelly', '8342729096ea3675442027381ff50dfe'), $user);
        $this->assertSame(['POST oauth2/token', 'GET users/@me', 'POST oauth2/token/revoke'], $api->requestedPaths());

        $basicAuth = 'Authorization: Basic '.base64_encode('client-id:client-secret');
        // The same redirect URI as in the authorization request, or Discord refuses the code
        $this->assertSame(['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => self::REDIRECT_URI], $api->formBody(0));
        $this->assertSame($basicAuth, $api->authorization(0));
        $this->assertSame('Authorization: Bearer '.FakeDiscordApi::ACCESS_TOKEN, $api->authorization(1));
        $this->assertSame(['token' => FakeDiscordApi::ACCESS_TOKEN, 'token_type_hint' => 'access_token'], $api->formBody(2));
        $this->assertSame($basicAuth, $api->authorization(2));
    }

    public function testFetchUserFailsWhenDiscordRefusesTheCode(): void
    {
        $api = new FakeDiscordApi(tokenStatus: 400);

        try {
            $this->client($api)->fetchUser('expired-code');
            $this->fail('A refused code must fail.');
        } catch (DiscordOAuthException) {
        }

        // No token, nothing to revoke
        $this->assertSame(['POST oauth2/token'], $api->requestedPaths());
    }

    public function testTheTokenIsRevokedAlsoWhenTheUserCannotBeRead(): void
    {
        $api = new FakeDiscordApi(userStatus: 401);

        try {
            $this->client($api)->fetchUser('the-code');
            $this->fail('An unreadable user must fail.');
        } catch (DiscordOAuthException) {
        }

        $this->assertSame(['POST oauth2/token', 'GET users/@me', 'POST oauth2/token/revoke'], $api->requestedPaths());
    }

    public function testAUserWithoutUsernameIsRefusedAndTheTokenRevoked(): void
    {
        $api = new FakeDiscordApi(user: ['id' => FakeDiscordApi::USER_ID, 'username' => '']);

        try {
            $this->client($api)->fetchUser('the-code');
            $this->fail('A user without username must fail.');
        } catch (DiscordOAuthException) {
        }

        $this->assertSame('POST oauth2/token/revoke', $api->requestedPaths()[2] ?? null);
    }

    public function testAFailedRevocationIsLoggedButKeepsTheUser(): void
    {
        $api = new FakeDiscordApi(revokeStatus: 500);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $user = $this->client($api, logger: $logger)->fetchUser('the-code');

        $this->assertSame(FakeDiscordApi::USER_ID, $user->id);
    }

    public function testDiscordUserKeepsNullForMissingNamesAndAvatarAndCutsLongNames(): void
    {
        $user = DiscordUser::fromApi(['id' => '42', 'username' => 'nelly', 'global_name' => '', 'avatar' => null]);
        $this->assertNull($user->globalName);
        $this->assertNull($user->avatar);

        $long = DiscordUser::fromApi(['id' => '42', 'username' => 'nelly', 'global_name' => str_repeat('é', 100)]);
        $this->assertSame(str_repeat('é', DiscordUser::MAX_NAME_LENGTH), $long->globalName);
    }

    public function testDiscordUserNeedsANumericId(): void
    {
        $this->expectException(DiscordOAuthException::class);

        DiscordUser::fromApi(['id' => 'not-a-snowflake', 'username' => 'nelly']);
    }

    public function testAvatarUrlIsTheUploadedAvatarOrTheDefaultOneOfTheId(): void
    {
        $this->assertSame(
            'https://cdn.discordapp.com/avatars/80351110224678912/a_1269e74af4df7417b13759eae50c83dc.webp?size=128',
            DiscordCdn::avatarUrl(FakeDiscordApi::USER_ID, 'a_1269e74af4df7417b13759eae50c83dc'),
        );
        // (80351110224678912 >> 22) % 6 = 19157197529 % 6
        $this->assertSame('https://cdn.discordapp.com/embed/avatars/5.png', DiscordCdn::avatarUrl(FakeDiscordApi::USER_ID, null));
    }
}
