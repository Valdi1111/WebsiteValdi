<?php

namespace App\Tests\GryphlineBundle\Service;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\AuthSessionStore;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Model\Auth\GryphlineLoginStepResult;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\GryphlineAuthService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportManagerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GryphlineAuthServiceTest extends TestCase
{
    private function createMockUser(int $id = 1): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    public function testDirectLoginSuccessGeneratesCredentialsAndSyncsProfiles(): void
    {
        $user = $this->createMockUser(1);
        $cache = new ArrayAdapter();
        $store = new AuthSessionStore('test-secret');

        $asClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 0,
                'msg' => 'OK',
                'data' => [
                    'token' => 'gryphline_account_token_123',
                    'hgId' => 'hg_999',
                ],
            ])),
        ]);

        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->once())
            ->method('getOrRefreshCredentials')
            ->with($this->callback(function (GryphlineAccount $acc): bool {
                return $acc->getAccountToken() === 'gryphline_account_token_123';
            }), true)
            ->willReturn(new SkportSessionCredentials(
                cred: 'session_cred_1',
                salt: 'session_salt_1',
                userId: 'u1',
                expiresAt: new \DateTimeImmutable('+45 minutes')
            ));

        $skportManager = $this->createMock(SkportManagerService::class);
        $skportManager->expects($this->once())->method('updateCachedGameProfiles');

        $repo = $this->createStub(GryphlineAccountRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new GryphlineAuthService(
            $asClient,
            $cache,
            new NullLogger(),
            $store,
            $skportAuth,
            $skportManager,
            $repo,
            $em
        );

        $result = $service->startLogin($user, 'doctor@gryphline.com', 'plain_password');

        $this->assertSame(GryphlineLoginStepResult::STATUS_AUTHENTICATED, $result->getStatus());
        $this->assertNotNull($result->getAccount());
        $this->assertSame('gryphline_account_token_123', $result->getAccount()->getAccountToken());
    }

    public function testCaptchaChallengeFlowMergesChallengeAndEncryptsPasswordInCache(): void
    {
        $user = $this->createMockUser(1);
        $cache = new ArrayAdapter();
        $store = new AuthSessionStore('test-secret');

        $capturedRequests = [];
        $responses = [
            // 1. Initial login -> status 1 (REQUIRE_CAPTCHA)
            new MockResponse(json_encode([
                'status' => 1,
                'msg' => 'Captcha required',
                'data' => [
                    'captcha' => [
                        'geetestId' => 'gt4-gryphline-id',
                        'riskType' => 'slide',
                        'challenge' => 'server-challenge-abc-789',
                    ],
                ],
            ])),
            // 2. Retry with captcha -> status 0 (OK)
            new MockResponse(json_encode([
                'status' => 0,
                'msg' => 'OK',
                'data' => [
                    'token' => 'final_account_token_after_captcha',
                    'hgId' => 'hg_100',
                ],
            ])),
        ];

        $asClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequests, &$responses) {
            $capturedRequests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses);
        });

        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->once())->method('getOrRefreshCredentials');

        $skportManager = $this->createStub(SkportManagerService::class);
        $repo = $this->createStub(GryphlineAccountRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        $service = new GryphlineAuthService(
            $asClient,
            $cache,
            new NullLogger(),
            $store,
            $skportAuth,
            $skportManager,
            $repo,
            $em
        );

        // Step 1: Trigger captcha
        $step1 = $service->startLogin($user, 'doctor@gryphline.com', 'EndfieldSecretPwd!');
        $this->assertSame(GryphlineLoginStepResult::STATUS_CAPTCHA_REQUIRED, $step1->getStatus());
        $sessionId = (string) $step1->getLoginSessionId();
        $this->assertNotEmpty($sessionId);
        $this->assertSame('gt4-gryphline-id', $step1->getCaptcha()['captcha_id'] ?? null);

        // Ensure password is encrypted in cache
        $cachedSession = $store->get($cache, $user, $sessionId);
        $this->assertNotNull($cachedSession);
        $this->assertStringNotContainsString('EndfieldSecretPwd!', json_encode($cachedSession));

        // Step 2: Submit GeeTest v4 solution
        $step2 = $service->submitCaptcha($user, $sessionId, [
            'captcha_id' => 'gt4-gryphline-id',
            'lot_number' => 'lot-99',
            'pass_token' => 'pass-99',
            'gen_time' => '1700000000',
            'captcha_output' => 'output-99',
        ]);

        $this->assertSame(GryphlineLoginStepResult::STATUS_AUTHENTICATED, $step2->getStatus());
        $this->assertSame('final_account_token_after_captcha', $step2->getAccount()?->getAccountToken());

        // Verify that the second request body included 'challenge' inside 'captcha'
        $req2Body = json_decode((string) ($capturedRequests[1]['options']['body'] ?? '{}'), true);
        $this->assertSame('server-challenge-abc-789', $req2Body['captcha']['challenge'] ?? null);
        $this->assertSame('lot-99', $req2Body['captcha']['lot_number'] ?? null);

        // Session must be deleted from cache
        $this->assertNull($store->get($cache, $user, $sessionId));
    }

    public function testInvalidCredentialsThrowsSkportAuthExceptionWithStatus104(): void
    {
        $user = $this->createMockUser(1);
        $asClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 104,
                'msg' => 'Login failed. Incorrect email address or password.',
                'type' => 'C1',
            ])),
        ]);

        $service = new GryphlineAuthService(
            $asClient,
            new ArrayAdapter(),
            new NullLogger(),
            new AuthSessionStore('test-secret'),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportManagerService::class),
            $this->createStub(GryphlineAccountRepository::class),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->expectException(SkportAuthException::class);
        $this->expectExceptionMessage('Login failed. Incorrect email address or password.');
        $service->startLogin($user, 'doctor@gryphline.com', 'wrong_password');
    }

    public function testAddLoginDeduplicatesExistingAccountWithSameAccountUid(): void
    {
        $user = $this->createMockUser(1);
        $existingAccount = (new GryphlineAccount())
            ->setUser($user)
            ->setAccountUid('hg_existing_777')
            ->setAccountToken('old_token');

        $asClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 0,
                'msg' => 'OK',
                'data' => [
                    'token' => 'new_token_for_777',
                    'hgId' => 'hg_existing_777',
                ],
            ])),
        ]);

        $repo = $this->createMock(GryphlineAccountRepository::class);
        $repo->expects($this->once())
            ->method('findOneBy')
            ->with(['user' => $user, 'accountUid' => 'hg_existing_777'])
            ->willReturn($existingAccount);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->once())->method('getOrRefreshCredentials')->with($existingAccount, true);

        $service = new GryphlineAuthService(
            $asClient,
            new ArrayAdapter(),
            new NullLogger(),
            new AuthSessionStore('test-secret'),
            $skportAuth,
            $this->createStub(SkportManagerService::class),
            $repo,
            $em
        );

        $result = $service->startLogin($user, 'doctor@gryphline.com', 'pwd');
        $this->assertSame($existingAccount, $result->getAccount());
        $this->assertSame('new_token_for_777', $existingAccount->getAccountToken());
    }

    public function testReloginRejectsMismatchedAccountUid(): void
    {
        $user = $this->createMockUser(1);
        $existingAccount = (new GryphlineAccount())
            ->setUser($user)
            ->setAccountUid('hg_111')
            ->setAccountToken('old_token');
        $ref = new \ReflectionProperty(GryphlineAccount::class, 'id');
        $ref->setValue($existingAccount, 7);

        $asClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 0,
                'msg' => 'OK',
                'data' => [
                    'token' => 'token_of_other_account',
                    'hgId' => 'hg_222',
                ],
            ])),
        ]);

        $service = new GryphlineAuthService(
            $asClient,
            new ArrayAdapter(),
            new NullLogger(),
            new AuthSessionStore('test-secret'),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportManagerService::class),
            $this->createStub(GryphlineAccountRepository::class),
            $this->createStub(EntityManagerInterface::class)
        );

        $this->expectException(SkportAuthException::class);
        $this->expectExceptionMessageMatches('/does not match existing Account #7/');
        $service->startLogin($user, 'other@gryphline.com', 'pwd', $existingAccount);
    }
}
