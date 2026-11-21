<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Exception\SkportUnavailableException;
use App\GryphlineBundle\Service\SkportAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SkportAuthServiceTest extends TestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    /**
     * @param array<string, mixed> $basicInfo response of /user/info/v1/basic
     */
    private function createService(array $basicInfo, ?EntityManagerInterface $entityManager = null): SkportAuthService
    {
        $asResponses = [
            new MockResponse(json_encode($basicInfo, JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['status' => 0, 'data' => ['code' => 'grant-code']], JSON_THROW_ON_ERROR)),
        ];
        $asClient = new MockHttpClient(function (string $method, string $url) use (&$asResponses): MockResponse {
            $this->requestedUrls[] = $url;

            return array_shift($asResponses);
        }, 'https://as.gryphline.com');
        $skportClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            return new MockResponse(json_encode(['code' => 0, 'data' => ['cred' => 'new-cred', 'token' => 'new-salt', 'userId' => 'u1']], JSON_THROW_ON_ERROR));
        }, 'https://zonai.skport.com');

        return new SkportAuthService(
            $asClient,
            $skportClient,
            new MockHttpClient(),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private static function account(?string $accountUid): GryphlineAccount
    {
        $account = (new GryphlineAccount())->setAccountToken('stored-token')->setAccountUid($accountUid);
        (new \ReflectionProperty(GryphlineAccount::class, 'id'))->setValue($account, 7);

        return $account;
    }

    public function testFetchAccountInfoReturnsTheTokenIdentityAsStrings(): void
    {
        $info = $this->createService(['status' => 0, 'data' => ['hgId' => 123456789, 'nickname' => 'Endministrator']])
            ->fetchAccountInfo(' pasted%2Btoken ');

        $this->assertSame('123456789', $info->hgId, 'A numeric hgId is normalized to a string');
        $this->assertSame('Endministrator', $info->nickname);
        $this->assertCount(1, $this->requestedUrls, 'Only step 1 of the OAuth flow');
        $this->assertStringContainsString('/user/info/v1/basic?token=pasted%2Btoken', $this->requestedUrls[0]);
    }

    public function testFetchAccountInfoRejectsAnInvalidToken(): void
    {
        $this->expectException(SkportAuthException::class);
        $this->expectExceptionMessage('OAuth Step 1 failed: token expired');

        $this->createService(['status' => 3, 'msg' => 'token expired'])->fetchAccountInfo('bad-token');
    }

    public function testRefreshKeepsTheSameIdentityAndStoresTheSession(): void
    {
        $account = self::account('123456789');

        $credentials = $this->createService(['status' => 0, 'data' => ['hgId' => 123456789, 'nickname' => 'Endministrator']])
            ->getOrRefreshCredentials($account, force: true);

        $this->assertSame('123456789', $credentials->getHgId());
        $this->assertSame('new-cred', $account->getSessionCred());
        $this->assertSame('123456789', $account->getAccountUid());
        $this->assertFalse($account->isTokenRefreshFailed());
    }

    public function testRefreshFillsAMissingAccountUid(): void
    {
        $account = self::account(null);

        $this->createService(['status' => 0, 'data' => ['hgId' => 'hg_1']])->getOrRefreshCredentials($account, force: true);

        $this->assertSame('hg_1', $account->getAccountUid());
    }

    public function testRefreshWithATokenOfAnotherGryphlineAccountFlagsTheAccountAndKeepsItsIdentity(): void
    {
        $account = self::account('hg_111')->setSessionCred('old-cred');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        try {
            $this->createService(['status' => 0, 'data' => ['hgId' => 'hg_222']], $entityManager)->getOrRefreshCredentials($account, force: true);
            $this->fail('A token of another Gryphline account must not refresh this account.');
        } catch (SkportAuthException $e) {
            $this->assertStringContainsString('Account #7 belongs to another Gryphline account (UID hg_222 instead of hg_111)', $e->getMessage());
        }

        $this->assertSame('hg_111', $account->getAccountUid(), 'Stored identity not overwritten');
        $this->assertSame('old-cred', $account->getSessionCred(), 'Session of the other account not stored');
        $this->assertTrue($account->isTokenRefreshFailed());
    }

    /**
     * Gryphline + binding APIs routed by path; the Endfield grant (type 1) succeeds or rejects the account token.
     */
    private function createU8Service(bool $grantAccepted, ?EntityManagerInterface $entityManager = null): SkportAuthService
    {
        $asClient = new MockHttpClient(function (string $method, string $url, array $options) use ($grantAccepted): MockResponse {
            $this->requestedUrls[] = $url;
            if (str_contains($url, '/user/info/v1/basic')) {
                return new MockResponse(json_encode(['status' => 0, 'data' => ['hgId' => 'hg_1']], JSON_THROW_ON_ERROR));
            }
            $body = json_decode((string) ($options['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (($body['type'] ?? null) === 0) {
                return new MockResponse(json_encode(['status' => 0, 'data' => ['code' => 'grant-code']], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode($grantAccepted ? ['status' => 0, 'data' => ['token' => 'endfield-oauth']] : ['status' => 3, 'msg' => 'token invalid'], JSON_THROW_ON_ERROR));
        }, 'https://as.gryphline.com');
        $skportClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            return new MockResponse(json_encode(['code' => 0, 'data' => ['cred' => 'new-cred', 'token' => 'new-salt', 'userId' => 'u1']], JSON_THROW_ON_ERROR));
        }, 'https://zonai.skport.com');
        $bindingClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;
            if (str_contains($url, 'binding_list')) {
                return new MockResponse(json_encode(['status' => 0, 'data' => ['list' => [
                    ['appCode' => 'endfield', 'bindingList' => [['uid' => 'ef-uid', 'roles' => [['roleId' => '4000001']]]]],
                ]]], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['status' => 0, 'data' => ['token' => 'u8-token']], JSON_THROW_ON_ERROR));
        });

        return new SkportAuthService($asClient, $skportClient, $bindingClient, $entityManager ?? $this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    public function testPullSyncTokenRejectedByGryphlineFlagsTheAccount(): void
    {
        $account = self::account('hg_1');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        try {
            $this->createU8Service(grantAccepted: false, entityManager: $entityManager)->getEndfieldU8Token($account, '4000001');
            $this->fail('A rejected account token must fail the pull sync.');
        } catch (SkportAuthException $e) {
            $this->assertStringContainsString('Endfield OAuth grant failed: token invalid', $e->getMessage());
        }

        $this->assertTrue($account->isTokenRefreshFailed());
    }

    public function testPullSyncOfAHealthyAccountDoesNotRenewTheSession(): void
    {
        $account = self::account('hg_1');

        $this->assertSame('u8-token', $this->createU8Service(grantAccepted: true)->getEndfieldU8Token($account, '4000001'));

        $this->assertFalse($account->isTokenRefreshFailed());
        $this->assertSame([], array_values(array_filter($this->requestedUrls, static fn (string $url): bool => str_contains($url, '/user/info/v1/basic'))), 'No session renewal');
    }

    public function testPullSyncOfAFlaggedAccountRenewsTheSessionFirstAndClearsTheFlag(): void
    {
        $account = self::account('hg_1')->setTokenRefreshFailed(true);

        $this->assertSame('u8-token', $this->createU8Service(grantAccepted: true)->getEndfieldU8Token($account, '4000001'));

        $this->assertFalse($account->isTokenRefreshFailed());
        $this->assertSame('new-cred', $account->getSessionCred(), 'Session renewed (identity verified) before the pull sync');
        $this->assertNotNull($account->getLastTokenRefreshedAt());
        $this->assertStringContainsString('/user/info/v1/basic', $this->requestedUrls[0]);
    }

    public function testPullSyncOfAFlaggedAccountWithATokenOfAnotherAccountStops(): void
    {
        $account = self::account('hg_other')->setTokenRefreshFailed(true);

        try {
            $this->createU8Service(grantAccepted: true)->getEndfieldU8Token($account, '4000001');
            $this->fail('The pulls of another Gryphline account must not be imported.');
        } catch (SkportAuthException $e) {
            $this->assertStringContainsString('belongs to another Gryphline account', $e->getMessage());
        }

        $this->assertTrue($account->isTokenRefreshFailed());
        $this->assertSame([], array_values(array_filter($this->requestedUrls, static fn (string $url): bool => str_contains($url, 'binding'))), 'No binding or u8 request');
    }

    private function createServiceAnswering(MockResponse $basicInfoResponse, ?EntityManagerInterface $entityManager = null): SkportAuthService
    {
        return new SkportAuthService(
            new MockHttpClient($basicInfoResponse, 'https://as.gryphline.com'),
            new MockHttpClient(),
            new MockHttpClient(),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function outageProvider(): iterable
    {
        yield 'no connection' => [new MockResponse('', ['error' => 'Could not resolve host: as.gryphline.com'])];
        yield 'server error' => [new MockResponse('Bad Gateway', ['http_code' => 502])];
        yield 'rate limited' => [new MockResponse('', ['http_code' => 429])];
        yield 'not json (captive portal)' => [new MockResponse('<html>Sign in to the network</html>')];
    }

    #[DataProvider('outageProvider')]
    public function testRefreshDuringAnOutageKeepsTheAccountState(MockResponse $response): void
    {
        $account = self::account('hg_1');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->createServiceAnswering($response, $entityManager)->getOrRefreshCredentials($account, force: true);
            $this->fail('An outage must be reported as unavailable.');
        } catch (SkportUnavailableException $e) {
            $this->assertStringNotContainsString('stored-token', $e->getMessage(), 'The token never appears in the message');
            $this->assertSame(502, $e->getStatusCode());
        }

        $this->assertFalse($account->isTokenRefreshFailed());
    }

    public function testPastedTokenCheckDuringAnOutageIsUnavailableNotInvalid(): void
    {
        $this->expectException(SkportUnavailableException::class);

        $this->createServiceAnswering(new MockResponse('', ['error' => 'Connection timed out']))->fetchAccountInfo('pasted-token');
    }

    public function testPullSyncDuringAGryphlineOutageDoesNotFlagTheAccount(): void
    {
        $account = self::account('hg_1');
        $service = new SkportAuthService(
            new MockHttpClient(new MockResponse('', ['http_code' => 503]), 'https://as.gryphline.com'),
            new MockHttpClient(),
            new MockHttpClient(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        $this->expectException(SkportUnavailableException::class);
        try {
            $service->getEndfieldU8Token($account, '4000001');
        } finally {
            $this->assertFalse($account->isTokenRefreshFailed());
        }
    }
}
