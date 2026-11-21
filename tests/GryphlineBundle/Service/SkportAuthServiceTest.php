<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Service\SkportAuthService;
use Doctrine\ORM\EntityManagerInterface;
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
}
