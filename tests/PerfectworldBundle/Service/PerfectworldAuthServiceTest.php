<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\GamesBundle\Service\AuthSessionStore;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Exception\PerfectworldApiException;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Model\Sdk\PwLoginResult;
use App\PerfectworldBundle\Model\Sdk\PwRole;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Service\PerfectworldAuthService;
use App\PerfectworldBundle\Service\PerfectworldProfileSyncService;
use App\PerfectworldBundle\Service\PerfectworldSdkClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

// Collaborators are mocks shared by all tests; each test sets expectations only on what it checks
#[AllowMockObjectsWithoutExpectations]
class PerfectworldAuthServiceTest extends TestCase
{
    private ArrayAdapter $cache;
    private AuthSessionStore $store;
    private PerfectworldSdkClient&MockObject $sdk;
    private PerfectworldProfileSyncService&MockObject $sync;
    private PerfectworldAccountRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->store = new AuthSessionStore('test-kernel-secret');
        $this->sdk = $this->createMock(PerfectworldSdkClient::class);
        $this->sync = $this->createMock(PerfectworldProfileSyncService::class);
        $this->repository = $this->createMock(PerfectworldAccountRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function createService(): PerfectworldAuthService
    {
        return new PerfectworldAuthService(
            $this->entityManager,
            $this->repository,
            $this->sdk,
            $this->sync,
            $this->store,
            $this->cache,
            new NullLogger(),
        );
    }

    private static function createUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function createAccount(User $user, string $pwUid, int $id = 7): PerfectworldAccount
    {
        $account = (new PerfectworldAccount())->setUser($user)->setPwUid($pwUid)->setToken('old-token')->setNdid(str_repeat('b', 64));
        (new \ReflectionProperty(PerfectworldAccount::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testStartSendsTheCodeAndOpensALoginSession(): void
    {
        $user = self::createUser(1);
        $this->sdk->expects($this->once())->method('sendEmailCode')
            ->with('user@example.com', $this->matchesRegularExpression('/^[0-9a-f]{64}$/'));

        $result = $this->createService()->startLogin($user, 'user@example.com');

        $this->assertSame(LoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED, $result->getStatus());
        $session = $this->store->get($this->cache, $user, (string) $result->getLoginSessionId());
        $this->assertSame('user@example.com', $session['email']);
        $this->assertNull($session['reloginAccountId']);
    }

    public function testResendKeepsTheSameDeviceIdAndStatus(): void
    {
        $user = self::createUser(1);
        $sentWith = [];
        $this->sdk->expects($this->exactly(2))->method('sendEmailCode')
            ->willReturnCallback(static function (string $email, string $ndid) use (&$sentWith): void {
                $sentWith[] = $ndid;
            });

        $service = $this->createService();
        $start = $service->startLogin($user, 'user@example.com');
        $resend = $service->resendCode($user, (string) $start->getLoginSessionId());

        $this->assertSame(LoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED, $resend->getStatus());
        $this->assertSame($sentWith[0], $sentWith[1]);
    }

    public function testVerifyCreatesTheAccountWithTheLoginDeviceIdAndSyncsProfiles(): void
    {
        $user = self::createUser(1);
        $service = $this->createService();
        $start = $service->startLogin($user, 'user@example.com');
        $ndid = $this->store->get($this->cache, $user, (string) $start->getLoginSessionId())['ndid'];

        $this->sdk->expects($this->once())->method('loginWithEmailCode')
            ->with('user@example.com', '123456', $ndid)
            ->willReturn(new PwLoginResult('2000000001', 'new-token-1234', 8));
        $this->sdk->expects($this->once())->method('getTokenExpiry')
            ->with('2000000001', 'new-token-1234', $ndid)
            ->willReturn(new \DateTimeImmutable('@1797851253'));
        $this->repository->method('findOneBy')->willReturn(null);
        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(PerfectworldAccount::class));
        $this->sync->expects($this->once())->method('syncProfiles')->with($this->isInstanceOf(PerfectworldAccount::class), null);

        $result = $service->verifyCode($user, (string) $start->getLoginSessionId(), '123456');

        $this->assertSame(LoginStepResult::STATUS_AUTHENTICATED, $result->getStatus());
        /** @var PerfectworldAccount $account */
        $account = $result->getAccount();
        $this->assertSame('2000000001', $account->getPwUid());
        $this->assertSame('new-token-1234', $account->getToken());
        $this->assertSame($ndid, $account->getNdid());
        $this->assertSame('user@example.com', $account->getEmail());
        $this->assertSame(PerfectworldAccount::LOGIN_METHOD_EMAIL_CODE, $account->getLoginMethod());
        $this->assertSame(1797851253, $account->getTokenExpiresAt()?->getTimestamp());
        $this->assertNull($this->store->get($this->cache, $user, (string) $start->getLoginSessionId()), 'The login session is consumed.');
    }

    public function testVerifyUpdatesTheExistingAccountWithTheSamePerfectWorldUid(): void
    {
        $user = self::createUser(1);
        $existing = self::createAccount($user, '2000000001')->setTokenInvalid(true);
        $service = $this->createService();
        $start = $service->startLogin($user, 'user@example.com');

        $this->sdk->method('loginWithEmailCode')->willReturn(new PwLoginResult('2000000001', 'new-token-1234'));
        $this->repository->expects($this->once())->method('findOneBy')->with(['user' => $user, 'pwUid' => '2000000001'])->willReturn($existing);
        $this->entityManager->expects($this->never())->method('persist');

        $result = $service->verifyCode($user, (string) $start->getLoginSessionId(), '123456');

        $this->assertSame($existing, $result->getAccount());
        $this->assertSame('new-token-1234', $existing->getToken());
        $this->assertFalse($existing->isTokenInvalid());
    }

    public function testFailedExpiryCheckDoesNotFailTheLogin(): void
    {
        $user = self::createUser(1);
        $service = $this->createService();
        $start = $service->startLogin($user, 'user@example.com');

        $this->sdk->method('loginWithEmailCode')->willReturn(new PwLoginResult('2000000001', 'new-token-1234'));
        $this->sdk->method('getTokenExpiry')->willThrowException(new PerfectworldAuthException());

        $result = $service->verifyCode($user, (string) $start->getLoginSessionId(), '123456');

        $this->assertSame(LoginStepResult::STATUS_AUTHENTICATED, $result->getStatus());
        $this->assertNull($result->getAccount()?->getTokenExpiresAt());
    }

    public function testReloginWithADifferentPerfectWorldAccountIsRejected(): void
    {
        $user = self::createUser(1);
        $account = self::createAccount($user, '2000000001');
        $service = $this->createService();
        $start = $service->startLogin($user, 'other@example.com', $account);

        $this->sdk->method('loginWithEmailCode')->willReturn(new PwLoginResult('2000000999', 'new-token-1234'));
        $this->repository->expects($this->once())->method('find')->with(7)->willReturn($account);

        $this->expectException(PerfectworldApiException::class);
        $this->expectExceptionMessage('does not match');
        $service->verifyCode($user, (string) $start->getLoginSessionId(), '123456');
    }

    public function testExpiredLoginSessionIsReported(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->createService()->verifyCode(self::createUser(1), 'missing-session', '123456');
    }

    public function testLoginSessionOfAnotherUserIsRejected(): void
    {
        $service = $this->createService();
        $start = $service->startLogin(self::createUser(1), 'user@example.com');

        $this->expectException(AccessDeniedException::class);
        $service->verifyCode(self::createUser(2), (string) $start->getLoginSessionId(), '123456');
    }

    public function testTokenLoginReusesTheValidationRolesForTheSync(): void
    {
        $user = self::createUser(1);
        $roles = [new PwRole('100000000001', 'Player_1', '23003', 'Europe', 58, null, null, null)];
        $this->sdk->expects($this->once())->method('getRoles')->with('2000000001', 'pasted-token-1234')->willReturn($roles);
        $this->repository->method('findOneBy')->willReturn(null);
        $this->sync->expects($this->once())->method('syncProfiles')->with($this->isInstanceOf(PerfectworldAccount::class), $roles);

        $account = $this->createService()->loginWithToken($user, '2000000001', 'pasted-token-1234');

        $this->assertSame(PerfectworldAccount::LOGIN_METHOD_TOKEN, $account->getLoginMethod());
        $this->assertNull($account->getEmail());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $account->getNdid());
    }

    public function testInvalidPastedTokenIsABadRequest(): void
    {
        $this->sdk->method('getRoles')->willThrowException(new PerfectworldAuthException());

        try {
            $this->createService()->loginWithToken(self::createUser(1), '2000000001', 'pasted-token-1234');
            $this->fail('Expected a PerfectworldApiException.');
        } catch (PerfectworldApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Invalid or expired token.', $e->getMessage());
        }
    }

    public function testTokenReloginWithAnotherPerfectWorldAccountIsRejected(): void
    {
        $user = self::createUser(1);
        $this->sdk->method('getRoles')->willReturn([]);

        $this->expectException(PerfectworldApiException::class);
        $this->createService()->loginWithToken($user, '2000000999', 'pasted-token-1234', self::createAccount($user, '2000000001'));
    }

    public function testReloginOfAnotherUsersAccountIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createService()->startLogin(self::createUser(1), 'user@example.com', self::createAccount(self::createUser(2), '2000000001'));
    }
}
