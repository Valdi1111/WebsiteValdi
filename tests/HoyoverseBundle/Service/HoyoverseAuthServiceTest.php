<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Service\AuthSessionStore;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyolabUnavailableException;
use App\HoyoverseBundle\Exception\HoyoverseAuthException;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabManagerService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\HoyoverseAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class HoyoverseAuthServiceTest extends TestCase
{
    private function createMockUser(int $id = 1): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    public function testGenerateAppLoginDsFormat(): void
    {
        $utils = new HoyolabUtilsService();
        $body = ['account' => 'enc_acc', 'password' => 'enc_pwd'];
        $ds = $utils->generateAppLoginDs($body);

        $parts = explode(',', $ds);
        $this->assertCount(3, $parts);
        $this->assertMatchesRegularExpression('/^\d+$/', $parts[0]);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{6}$/', $parts[1]);

        $expectedHash = md5(sprintf(
            'salt=%s&t=%s&r=%s&b=%s&q=',
            HoyolabUtilsService::APP_LOGIN_SALT,
            $parts[0],
            $parts[1],
            json_encode($body, JSON_UNESCAPED_SLASHES)
        ));
        $this->assertSame($expectedHash, $parts[2]);
    }

    public function testDirectLoginSuccessRenewsCookieAndPersistsDeviceId(): void
    {
        $user = $this->createMockUser(1);
        $cache = new ArrayAdapter();
        $store = new AuthSessionStore('test-secret');
        $utils = new HoyolabUtilsService();

        $passportClient = new MockHttpClient([
            new MockResponse(json_encode([
                'retcode' => 0,
                'message' => 'OK',
                'data' => [
                    'token' => ['token_type' => 1, 'token' => 'v2_stoken_xyz'],
                    'user_info' => ['aid' => '12345678', 'mid' => 'mid_abc'],
                ],
            ])),
        ]);

        $cookieUtils = $this->createMock(HoyolabCookieUtilsService::class);
        $cookieUtils->expects($this->once())
            ->method('renew')
            ->willReturnCallback(function (ParsedCookie $cookie): bool {
                $this->assertSame('v2_stoken_xyz', $cookie->getStokenV2());
                $this->assertSame('12345678', $cookie->getLtuidV2());
                $this->assertSame('mid_abc', $cookie->getLtmidV2());
                $cookie->setLtokenV2('renewed_ltoken_v2');
                $cookie->setCookieTokenV2('renewed_cookie_token_v2');

                return true;
            });

        $repo = $this->createMock(HoyoverseAccountRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['user' => $user, 'accountUid' => '12345678'])->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $manager = $this->createMock(HoyolabManagerService::class);
        $manager->expects($this->once())->method('updateCachedGameProfilesByGameRecords');

        $service = new HoyoverseAuthService(
            $passportClient,
            $cache,
            new NullLogger(),
            $store,
            $utils,
            $cookieUtils,
            $repo,
            $em,
            $manager
        );

        $result = $service->startLogin($user, 'player@example.com', 'plain_pass_123');

        $this->assertSame(LoginStepResult::STATUS_AUTHENTICATED, $result->getStatus());
        $account = $result->getAccount();
        $this->assertNotNull($account);
        $this->assertSame('12345678', $account->getAccountUid());
        $this->assertNotNull($account->getDeviceId());
        $this->assertSame(16, strlen((string) $account->getDeviceId()));
        $this->assertStringContainsString('stoken_v2=v2_stoken_xyz', (string) $account->getCookie());
        $this->assertStringContainsString('ltoken_v2=renewed_ltoken_v2', (string) $account->getCookie());
        $this->assertStringContainsString('cookie_token_v2=renewed_cookie_token_v2', (string) $account->getCookie());
        $this->assertFalse($account->isCookieRefreshFailed());
    }

    public function testCombinedCaptchaAndEmailVerificationFlow(): void
    {
        $user = $this->createMockUser(1);
        $cache = new ArrayAdapter();
        $store = new AuthSessionStore('test-secret');
        $utils = new HoyolabUtilsService();

        $capturedRequests = [];
        $responses = [
            // 1. Initial login -> -3101 Captcha required
            new MockResponse(
                json_encode(['retcode' => -3101, 'message' => 'Captcha triggered']),
                [
                    'response_headers' => [
                        'x-rpc-aigis: ' . json_encode([
                            'session_id' => 'aigis-sess-001',
                            'mmt_type' => 1,
                            'data' => json_encode([
                                'use_v4' => true,
                                'gt' => 'geetest-v4-id',
                                'risk_type' => 'slide',
                            ]),
                        ]),
                    ],
                ]
            ),
            // 2. Submit captcha -> -3239 Email verification required
            new MockResponse(
                json_encode(['retcode' => -3239, 'message' => 'Email verification required']),
                [
                    'response_headers' => [
                        'x-rpc-verify: {"verify_str":"{\"ticket\":\"ticket-xyz-999\",\"verify_type\":2}"}',
                    ],
                ]
            ),
            // 3. verifyActionTicketPartly -> 0 OK
            new MockResponse(json_encode(['retcode' => 0, 'message' => 'OK'])),
            // 4. Final appLoginByPassword with x-rpc-verify -> 0 OK
            new MockResponse(json_encode([
                'retcode' => 0,
                'message' => 'OK',
                'data' => [
                    'token' => ['token_type' => 1, 'token' => 'v2_stoken_final'],
                    'user_info' => ['aid' => '99887766', 'mid' => 'mid_final'],
                ],
            ])),
        ];

        $passportClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequests, &$responses) {
            $capturedRequests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses);
        });

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('renew')->willReturnCallback(function (ParsedCookie $c): bool {
            $c->setLtokenV2('ltok')->setCookieTokenV2('ctok');

            return true;
        });

        $repo = $this->createStub(HoyoverseAccountRepository::class);
        $repo->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $manager = $this->createStub(HoyolabManagerService::class);

        $service = new HoyoverseAuthService(
            $passportClient,
            $cache,
            new NullLogger(),
            $store,
            $utils,
            $cookieUtils,
            $repo,
            $em,
            $manager
        );

        // Step 1: Start login -> captcha_required
        $step1 = $service->startLogin($user, 'player@example.com', 'super_secret_plain_pwd');
        $this->assertSame(LoginStepResult::STATUS_CAPTCHA_REQUIRED, $step1->getStatus());
        $sessionId = (string) $step1->getLoginSessionId();
        $this->assertNotEmpty($sessionId);

        // Verify password is NEVER stored in plaintext in the cache
        $cachedSession = $store->get($cache, $user, $sessionId);
        $this->assertNotNull($cachedSession);
        $this->assertStringNotContainsString('super_secret_plain_pwd', json_encode($cachedSession));

        // Step 2: Submit captcha (including extraneous session_id to verify it gets stripped)
        $step2 = $service->submitCaptcha($user, $sessionId, [
            'session_id' => 'should-be-stripped',
            'captcha_id' => 'geetest-v4-id',
            'lot_number' => 'lot-1',
            'pass_token' => 'pass-1',
            'gen_time' => '123456',
            'captcha_output' => 'out-1',
        ]);
        $this->assertSame(LoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED, $step2->getStatus());

        // Inspect x-rpc-aigis header sent in request #2
        $req2Headers = $capturedRequests[1]['options']['normalized_headers']['x-rpc-aigis'][0] ?? '';
        $this->assertStringStartsWith('x-rpc-aigis: aigis-sess-001;', $req2Headers);
        [, $b64Part] = explode(';', substr($req2Headers, strlen('x-rpc-aigis: ')), 2);
        $decodedAigisJson = json_decode((string) base64_decode($b64Part, true), true);
        $this->assertArrayNotHasKey('session_id', $decodedAigisJson);
        $this->assertSame('lot-1', $decodedAigisJson['lot_number']);

        // Step 3: Verify email OTP
        $step3 = $service->verifyEmail($user, $sessionId, '654321');
        $this->assertSame(LoginStepResult::STATUS_AUTHENTICATED, $step3->getStatus());

        // Inspect x-rpc-verify header sent in request #4: verify_str must still be a nested JSON string
        $req4VerifyHeader = $capturedRequests[3]['options']['normalized_headers']['x-rpc-verify'][0] ?? '';
        $this->assertSame('x-rpc-verify: {"verify_str":"{\"ticket\":\"ticket-xyz-999\",\"verify_type\":2}"}', $req4VerifyHeader);

        // Session should be deleted from cache after authentication
        $this->assertNull($store->get($cache, $user, $sessionId));
    }

    public function testReloginReusesDeviceIdAndRejectsMismatchedLtuid(): void
    {
        $user = $this->createMockUser(1);
        $cache = new ArrayAdapter();
        $store = new AuthSessionStore('test-secret');
        $utils = new HoyolabUtilsService();

        $existingAccount = (new HoyoverseAccount())
            ->setUser($user)
            ->setAccountUid('111111')
            ->setDeviceId('fixeddeviceid123')
            ->setCookie('ltoken_v2=old; ltuid_v2=111111; ltmid_v2=oldmid')
            ->setCookieRefreshFailed(true);
        $ref = new \ReflectionProperty(HoyoverseAccount::class, 'id');
        $ref->setValue($existingAccount, 42);

        $passportClient = new MockHttpClient([
            new MockResponse(json_encode([
                'retcode' => 0,
                'message' => 'OK',
                'data' => [
                    'token' => ['token_type' => 1, 'token' => 'v2_stoken_other'],
                    'user_info' => ['aid' => '999999', 'mid' => 'other_mid'],
                ],
            ])),
        ]);

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('renew')->willReturn(true);
        $cookieUtils->method('extractAccountUid')->willReturn('111111');

        $repo = $this->createStub(HoyoverseAccountRepository::class);
        $repo->method('find')->willReturn($existingAccount);

        $em = $this->createStub(EntityManagerInterface::class);
        $manager = $this->createStub(HoyolabManagerService::class);

        $service = new HoyoverseAuthService(
            $passportClient,
            $cache,
            new NullLogger(),
            $store,
            $utils,
            $cookieUtils,
            $repo,
            $em,
            $manager
        );

        $this->expectException(HoyoverseAuthException::class);
        $this->expectExceptionMessageMatches('/does not match existing Account #42/');
        $service->startLogin($user, 'wrong@example.com', 'pwd', $existingAccount);
    }

    public function testReloginThrowsAccessDeniedForAnotherUsersAccount(): void
    {
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);
        $accountOfUser2 = (new HoyoverseAccount())->setUser($user2);

        $service = new HoyoverseAuthService(
            new MockHttpClient(),
            new ArrayAdapter(),
            new NullLogger(),
            new AuthSessionStore('test-secret'),
            new HoyolabUtilsService(),
            $this->createStub(HoyolabCookieUtilsService::class),
            $this->createStub(HoyoverseAccountRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(HoyolabManagerService::class)
        );

        $this->expectException(AccessDeniedException::class);
        $service->startLogin($user1, 'user@example.com', 'pwd', $accountOfUser2);
    }

    public function testLoginWhileHoyoverseIsUnreachableReportsItWithoutSavingAnything(): void
    {
        $passportClient = new MockHttpClient([
            new MockResponse(json_encode([
                'retcode' => 0,
                'data' => [
                    'token' => ['token_type' => 1, 'token' => 'v2_stoken_xyz'],
                    'user_info' => ['aid' => '12345678', 'mid' => 'mid_abc'],
                ],
            ])),
        ]);
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('renew')->willThrowException(new HoyolabUnavailableException('HoYoverse passport API could not be reached.'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $service = new HoyoverseAuthService(
            $passportClient,
            new ArrayAdapter(),
            new NullLogger(),
            new AuthSessionStore('test-secret'),
            new HoyolabUtilsService(),
            $cookieUtils,
            $this->createStub(HoyoverseAccountRepository::class),
            $em,
            $this->createStub(HoyolabManagerService::class),
        );

        try {
            $service->startLogin($this->createMockUser(1), 'player@example.com', 'plain_pass_123');
            $this->fail('The login cannot complete without ltoken_v2.');
        } catch (HoyoverseAuthException $e) {
            $this->assertSame('HoYoverse could not be reached to complete the login. Please try again later.', $e->getMessage());
            $this->assertSame(502, $e->getStatusCode());
        }
    }

    public function testGameProfilesSyncFailureAfterLoginIsOnlyLogged(): void
    {
        $account = (new HoyoverseAccount())->setUser($this->createMockUser(1))->setCookie('ltoken_v2=v2_x; ltuid_v2=222');
        $manager = $this->createMock(HoyolabManagerService::class);
        $manager->expects($this->once())->method('updateCachedGameProfilesByGameRecords')->with($account)
            ->willThrowException(new \RuntimeException('HoYoLAB is down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('HoYoLAB is down'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = new HoyoverseAuthService(
            new MockHttpClient(),
            new ArrayAdapter(),
            $logger,
            new AuthSessionStore('test-secret'),
            new HoyolabUtilsService(),
            $this->createStub(HoyolabCookieUtilsService::class),
            $this->createStub(HoyoverseAccountRepository::class),
            $em,
            $manager,
        );

        // The account is already saved: the failure must not reach the caller
        $service->syncGameProfilesAfterLogin($account);
    }
}
