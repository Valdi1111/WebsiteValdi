<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\PerfectworldBundle\Exception\PerfectworldApiException;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Service\PerfectworldSdkClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PerfectworldSdkClientTest extends TestCase
{
    private const string NDID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param list<array{0: string, 1: string}> $requests Collected [method, url] pairs
     */
    private function createClient(array $responses, array &$requests = []): PerfectworldSdkClient
    {
        $mock = new MockHttpClient(function (string $method, string $url) use (&$responses, &$requests): MockResponse {
            $requests[] = [$method, $url];

            return array_shift($responses);
        }, 'https://i.pwsdk.com');

        return new PerfectworldSdkClient($mock, new NullLogger());
    }

    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data));
    }

    /**
     * @return array<string, string>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testSendEmailCodeUsesTheCaptchaFreeEndpoint(): void
    {
        $requests = [];
        $client = $this->createClient([self::json(['code' => 0, 'message' => 'success', 'result' => []])], $requests);

        $client->sendEmailCode('user@example.com', self::NDID);

        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0][0]);
        $this->assertStringStartsWith('https://i.pwsdk.com/web/user/email/code/getV0.do?', $requests[0][1]);
        $query = self::query($requests[0][1]);
        $this->assertSame('user@example.com', $query['email']);
        $this->assertSame(self::NDID, $query['ndid']);
        $this->assertSame((string) PerfectworldSdkClient::APP_ID, $query['appId']);
        $this->assertSame(PerfectworldSdkClient::PACKAGE_NAME, $query['packageName']);
    }

    public function testLoginWithEmailCodeReturnsUidAndToken(): void
    {
        $requests = [];
        $client = $this->createClient([self::json([
            'code' => 0,
            'result' => ['loginType' => 8, 'uid' => 2000000001, 'token' => '11111111-2222-4333-8444-555555555555'],
        ])], $requests);

        $result = $client->loginWithEmailCode('user@example.com', '123456', self::NDID);

        $this->assertSame('2000000001', $result->uid);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $result->token);
        $this->assertSame(8, $result->loginType);
        $query = self::query($requests[0][1]);
        $this->assertSame('123456', $query['emailCode']);
        $this->assertSame(['sdkServerVersion' => PerfectworldSdkClient::SDK_SERVER_VERSION], json_decode($query['xClientData'], true));
    }

    public function testWrongCodeSurfacesTheApiMessage(): void
    {
        $client = $this->createClient([self::json(['code' => 10023, 'message' => 'Verification code error'])]);

        $this->expectException(PerfectworldApiException::class);
        $this->expectExceptionMessage('Verification code error');
        $client->loginWithEmailCode('user@example.com', '000000', self::NDID);
    }

    public function testTokenExpiryIsReadWithTheLoginDeviceId(): void
    {
        $requests = [];
        $client = $this->createClient([self::json(['code' => 0, 'result' => ['tokenExpireTime' => 1797851253]])], $requests);

        $expiry = $client->getTokenExpiry('2000000001', 'token-1234', self::NDID);

        $this->assertSame(1797851253, $expiry?->getTimestamp());
        $this->assertSame(date_default_timezone_get(), $expiry?->getTimezone()->getName(), 'Stored as local time in DATETIME columns');
        $query = self::query($requests[0][1]);
        $this->assertSame(self::NDID, $query['ndid']);
        $xClientData = json_decode($query['xClientData'], true);
        $this->assertSame(PerfectworldSdkClient::PACKAGE_NAME, $xClientData['packageName']);
    }

    public function testTokenExpiryIsBestEffort(): void
    {
        $client = $this->createClient([self::json(['code' => 50001, 'message' => 'Network error(50001479)'])]);

        $this->assertNull($client->getTokenExpiry('2000000001', 'token-1234', self::NDID));
    }

    public function testTokenExpiryRethrowsAnInvalidToken(): void
    {
        $client = $this->createClient([self::json(['code' => 20001, 'message' => 'Login information expired, please log in again.(20001400)'])]);

        $this->expectException(PerfectworldAuthException::class);
        $client->getTokenExpiry('2000000001', 'token-1234', self::NDID);
    }

    public function testRolesAreParsedFromTheRoleMap(): void
    {
        $requests = [];
        $client = $this->createClient([self::json([
            'code' => 0,
            'result' => ['roleList' => [[
                'roleMap' => [
                    'roleId' => '100000000001',
                    'roleName' => 'Player_1',
                    'serverId' => '23003',
                    'serverName' => 'Europe',
                    'lev' => 58,
                    'avatar' => 'Fashion_1042_1',
                    'firstLogin' => '2026-04-29 10:33:00',
                    'lastLogin' => '2026-11-21 02:38:05',
                ],
                'roleId' => '100000000001',
            ]]],
        ])], $requests);

        $roles = $client->getRoles('2000000001', 'token-1234');

        $this->assertCount(1, $roles);
        $this->assertSame('100000000001', $roles[0]->roleId);
        $this->assertSame('Player_1', $roles[0]->roleName);
        $this->assertSame('23003', $roles[0]->serverId);
        $this->assertSame(58, $roles[0]->level);
        $this->assertSame('Fashion_1042_1', $roles[0]->avatar);
        $this->assertStringStartsWith('https://i.pwsdk.com/game/getRoleList/bi.do?', $requests[0][1]);
        $this->assertArrayNotHasKey('callback', self::query($requests[0][1]));
    }

    public function testExpiredTokenOnRolesThrowsAuthException(): void
    {
        $client = $this->createClient([self::json(['code' => 20001, 'message' => 'Login information expired'])]);

        $this->expectException(PerfectworldAuthException::class);
        $client->getRoles('2000000001', 'bad-token-0000');
    }

    public function testTransportErrorsNeverExposeTheRequestUrl(): void
    {
        $client = $this->createClient([new MockResponse('', ['error' => 'Could not resolve host for https://i.pwsdk.com/?token=secret-token-value'])]);

        try {
            $client->getRoles('2000000001', 'secret-token-value');
            $this->fail('Expected a PerfectworldApiException.');
        } catch (PerfectworldApiException $e) {
            $this->assertStringNotContainsString('secret-token-value', $e->getMessage());
            $this->assertStringNotContainsString('https://', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertSame(502, $e->getStatusCode());
        }
    }

    public function testGeneratedDeviceIdLooksLikeABrowserFingerprint(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', PerfectworldSdkClient::generateNdid());
        $this->assertNotSame(PerfectworldSdkClient::generateNdid(), PerfectworldSdkClient::generateNdid());
    }
}
