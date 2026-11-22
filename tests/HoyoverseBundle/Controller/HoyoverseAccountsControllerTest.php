<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\HoyoverseBundle\Controller\ApiAccountsController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyoverseAuthException;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyoverseAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * "Paste Cookie": on an existing account (account_id in the payload) the cookie must belong to the same HoYoverse account,
 * and every saved cookie downloads the game profiles like the credentials login does.
 */
class HoyoverseAccountsControllerTest extends TestCase
{
    private const string COOKIE_OF_222 = 'ltoken_v2=v2_new; ltuid_v2=222; ltmid_v2=mid_222';

    private static function createUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function account(User $owner, ?string $accountUid): HoyoverseAccount
    {
        $account = (new HoyoverseAccount())->setUser($owner)->setCookie('ltoken_v2=v2_old; ltuid_v2=111')->setAccountUid($accountUid);
        (new \ReflectionProperty(HoyoverseAccount::class, 'id'))->setValue($account, 7);

        return $account;
    }

    private function createController(?HoyoverseAccount $account, User $currentUser, EntityManagerInterface $entityManager): ApiAccountsController
    {
        $repository = $this->createStub(HoyoverseAccountRepository::class);
        $repository->method('find')->willReturn($account);

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('{}');
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($currentUser, 'main', ['ROLE_USER_HOYOVERSE']));

        $container = new Container();
        $container->set('serializer', $serializer);
        $container->set('security.authorization_checker', new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new GameAccountOwnerVoter()])));

        $controller = new ApiAccountsController($entityManager, $repository);
        $controller->setContainer($container);

        return $controller;
    }

    private function authServiceNeverSyncing(): HoyoverseAuthService
    {
        $authService = $this->createMock(HoyoverseAuthService::class);
        $authService->expects($this->never())->method('syncGameProfilesAfterLogin');

        return $authService;
    }

    private static function pasteCookie(string $cookie, ?int $accountId = 7): Request
    {
        $payload = ['cookie' => $cookie];
        if ($accountId !== null) {
            $payload['account_id'] = $accountId;
        }

        return Request::create('/api/accounts', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    public function testCookieOfAnotherHoyoverseAccountIsRejectedAndNotSaved(): void
    {
        $user = self::createUser(1);
        $account = self::account($user, '111');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->createController($account, $user, $entityManager)->apiAccountsAdd(self::pasteCookie(self::COOKIE_OF_222), $user, $this->authServiceNeverSyncing());
            $this->fail('Expected an account mismatch.');
        } catch (HoyoverseAuthException $e) {
            $this->assertSame('The logged-in HoYoverse account (UID 222) does not match existing Account #7 (UID 111).', $e->getMessage());
            $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getStatusCode());
        }

        $this->assertSame('111', $account->getAccountUid());
        $this->assertSame('ltoken_v2=v2_old; ltuid_v2=111', $account->getCookie());
    }

    public function testCookieOfTheSameAccountUpdatesItAndDownloadsItsGameProfiles(): void
    {
        $user = self::createUser(1);
        $account = self::account($user, '222')->setCookieRefreshFailed(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $authService = $this->createMock(HoyoverseAuthService::class);
        $authService->expects($this->once())->method('syncGameProfilesAfterLogin')
            ->with($this->callback(static fn (HoyoverseAccount $synced): bool => $synced === $account && $synced->getCookie() === self::COOKIE_OF_222));

        $response = $this->createController($account, $user, $entityManager)->apiAccountsAdd(self::pasteCookie(self::COOKIE_OF_222), $user, $authService);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(self::COOKIE_OF_222, $account->getCookie());
        $this->assertFalse($account->isCookieRefreshFailed());
    }

    public function testNewAccountFromCookieIsCreatedAndDownloadsItsGameProfiles(): void
    {
        $user = self::createUser(1);
        $created = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(static function (HoyoverseAccount $account) use (&$created): void {
                $created = $account;
            });
        $entityManager->expects($this->once())->method('flush');
        $authService = $this->createMock(HoyoverseAuthService::class);
        // The profiles are downloaded for the saved account, so after it was persisted
        $authService->expects($this->once())->method('syncGameProfilesAfterLogin')
            ->with($this->callback(static function (HoyoverseAccount $synced) use (&$created): bool {
                return $created !== null && $synced === $created;
            }));

        $response = $this->createController(null, $user, $entityManager)->apiAccountsAdd(self::pasteCookie(self::COOKIE_OF_222, null), $user, $authService);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame('222', $created->getAccountUid());
        $this->assertSame($user, $created->getUser());
    }

    public function testAccountWithoutStoredUidTakesTheCookieUid(): void
    {
        $user = self::createUser(1);
        $account = self::account($user, null);

        $this->createController($account, $user, $this->createStub(EntityManagerInterface::class))
            ->apiAccountsAdd(self::pasteCookie(self::COOKIE_OF_222), $user, $this->createStub(HoyoverseAuthService::class));

        $this->assertSame('222', $account->getAccountUid());
    }

    public function testAccountOfAnotherUserIsDenied(): void
    {
        $account = self::account(self::createUser(2), '222');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);
        $this->createController($account, self::createUser(1), $entityManager)
            ->apiAccountsAdd(self::pasteCookie(self::COOKIE_OF_222), self::createUser(1), $this->authServiceNeverSyncing());
    }
}
