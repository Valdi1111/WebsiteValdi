<?php

namespace App\Tests\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GryphlineBundle\Controller\ApiAccountsController;
use App\GryphlineBundle\Controller\ApiGameProfilesController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Model\SkportAccountInfo;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\GryphlineAuthService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportManagerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class GryphlineControllersTest extends TestCase
{
    private function createMockUser(int $id = 1): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);
        return $user;
    }

    private function attachMockContainer(object $controller, ?User $currentUser = null): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('{}');

        $tokenStorage = new TokenStorage();
        if ($currentUser !== null) {
            $tokenStorage->setToken(new UsernamePasswordToken($currentUser, 'main', ['ROLE_USER_GRYPHLINE']));
        }

        $container = new Container();
        $container->set('serializer', $serializer);
        $container->set('security.authorization_checker', new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new GameAccountOwnerVoter()])));

        if (method_exists($controller, 'setContainer')) {
            $controller->setContainer($container);
        }
    }

    public function testApiAccountsGet(): void
    {
        $user = $this->createMockUser();
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createMock(GryphlineAccountRepository::class);

        $account = (new GryphlineAccount())->setUser($user);
        $repo->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user], ['id' => 'ASC'])
            ->willReturn([$account]);

        $controller = new ApiAccountsController($em, $repo);
        $this->attachMockContainer($controller);

        $response = $controller->apiAccountsGet($user);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function tokenRequest(array $payload): Request
    {
        return Request::create('/api/accounts', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    private function accountInfoService(?string $hgId, string $expectedToken = 'pasted-token'): SkportAuthService&MockObject
    {
        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->once())->method('fetchAccountInfo')->with($expectedToken)->willReturn(new SkportAccountInfo($hgId, 'Endministrator'));

        return $skportAuth;
    }

    public function testApiAccountsAddCreatesAccountFromTokenIdentity(): void
    {
        $user = $this->createMockUser();
        $repo = $this->createMock(GryphlineAccountRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['user' => $user, 'accountUid' => 'hg_42'])->willReturn(null);

        $created = (new GryphlineAccount())->setUser($user)->setAccountUid('hg_42');
        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->once())
            ->method('finalizeAuthenticatedAccount')
            ->with($user, 'pasted-token', null, 'hg_42')
            ->willReturn($created);

        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $repo);
        $this->attachMockContainer($controller, $user);

        $response = $controller->apiAccountsAdd($this->tokenRequest(['account_token' => ' pasted-token ']), $user, $this->accountInfoService('hg_42'), $authService);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testApiAccountsAddWithKnownHgIdUpdatesTheExistingAccount(): void
    {
        $user = $this->createMockUser();
        $existing = (new GryphlineAccount())->setUser($user)->setAccountUid('hg_42')->setAccountToken('old-token');
        $repo = $this->createStub(GryphlineAccountRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->once())
            ->method('finalizeAuthenticatedAccount')
            ->with($user, 'pasted-token', null, 'hg_42')
            ->willReturn($existing);

        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $repo);
        $this->attachMockContainer($controller, $user);

        $response = $controller->apiAccountsAdd($this->tokenRequest(['account_token' => 'pasted-token']), $user, $this->accountInfoService('hg_42'), $authService);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Deduplicated by hgId, not by token');
    }

    public function testApiAccountsAddWithAccountIdReloginsThatAccount(): void
    {
        $user = $this->createMockUser();
        $account = (new GryphlineAccount())->setUser($this->createMockUser())->setAccountUid('hg_42');
        $repo = $this->createMock(GryphlineAccountRepository::class);
        $repo->expects($this->once())->method('find')->with(7)->willReturn($account);

        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->once())
            ->method('finalizeAuthenticatedAccount')
            ->with($user, 'pasted-token', $account, 'hg_42')
            ->willReturn($account);

        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $repo);
        $this->attachMockContainer($controller, $user);

        $response = $controller->apiAccountsAdd($this->tokenRequest(['account_token' => 'pasted-token', 'account_id' => 7]), $user, $this->accountInfoService('hg_42'), $authService);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testApiAccountsAddReloginOfAnotherUsersAccountIsDeniedBeforeCallingGryphline(): void
    {
        $user = $this->createMockUser(1);
        $repo = $this->createStub(GryphlineAccountRepository::class);
        $repo->method('find')->willReturn((new GryphlineAccount())->setUser($this->createMockUser(2)));

        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->never())->method('fetchAccountInfo');
        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->never())->method('finalizeAuthenticatedAccount');

        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $repo);
        $this->attachMockContainer($controller, $user);

        $this->expectException(AccessDeniedException::class);
        $controller->apiAccountsAdd($this->tokenRequest(['account_token' => 'pasted-token', 'account_id' => 7]), $user, $skportAuth, $authService);
    }

    public function testApiAccountsAddRejectsAnInvalidTokenBeforeSavingAnything(): void
    {
        $user = $this->createMockUser();
        $skportAuth = $this->createMock(SkportAuthService::class);
        $skportAuth->expects($this->once())->method('fetchAccountInfo')->willThrowException(new SkportAuthException('OAuth Step 1 failed: token invalid'));
        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->never())->method('finalizeAuthenticatedAccount');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $controller = new ApiAccountsController($em, $this->createStub(GryphlineAccountRepository::class));
        $this->attachMockContainer($controller, $user);

        $this->expectException(SkportAuthException::class);
        $controller->apiAccountsAdd($this->tokenRequest(['account_token' => 'pasted-token']), $user, $skportAuth, $authService);
    }

    public function testApiAccountsAddRejectsATokenWithoutHgId(): void
    {
        $user = $this->createMockUser();
        $authService = $this->createMock(GryphlineAuthService::class);
        $authService->expects($this->never())->method('finalizeAuthenticatedAccount');

        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $this->createStub(GryphlineAccountRepository::class));
        $this->attachMockContainer($controller, $user);

        $this->expectException(SkportAuthException::class);
        $this->expectExceptionMessage('did not return the account UID');
        $controller->apiAccountsAdd($this->tokenRequest(['account_token' => 'pasted-token']), $user, $this->accountInfoService(null), $authService);
    }

    public function testApiAccountsAddThrowsWhenTokenEmpty(): void
    {
        $user = $this->createMockUser();
        $controller = new ApiAccountsController($this->createStub(EntityManagerInterface::class), $this->createStub(GryphlineAccountRepository::class));
        $this->attachMockContainer($controller, $user);

        $this->expectException(BadRequestHttpException::class);
        $controller->apiAccountsAdd($this->tokenRequest(['account_token' => '']), $user, $this->createStub(SkportAuthService::class), $this->createStub(GryphlineAuthService::class));
    }

    public function testApiAccountsSyncRefreshesProfiles(): void
    {
        $account = (new GryphlineAccount())->setUser($this->createMockUser());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $manager = $this->createMock(SkportManagerService::class);
        $manager->expects($this->once())->method('updateCachedGameProfiles')->with($account);

        $controller = new ApiAccountsController($em, $this->createStub(GryphlineAccountRepository::class));
        $this->attachMockContainer($controller);

        $this->assertSame(Response::HTTP_OK, $controller->apiAccountsSync($account, $manager)->getStatusCode());
    }

    public function testApiAccountsDeleteRemovesTheAccount(): void
    {
        $account = (new GryphlineAccount())->setUser($this->createMockUser());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($account);
        $em->expects($this->once())->method('flush');

        $controller = new ApiAccountsController($em, $this->createStub(GryphlineAccountRepository::class));
        $this->attachMockContainer($controller);

        $this->assertSame(Response::HTTP_OK, $controller->apiAccountsDelete($account)->getStatusCode());
    }

    public function testApiGameProfilesGet(): void
    {
        $user = $this->createMockUser();
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())->setAccount($account)->setNickname('Player');
        $account->addGameProfile($profile);

        $em = $this->createStub(EntityManagerInterface::class);
        $controller = new ApiGameProfilesController($em, $this->createStub(ServiceLocator::class), sys_get_temp_dir());
        $this->attachMockContainer($controller);

        $response = $controller->apiGameProfilesGet($account);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testApiGameProfilesUpdateSettingsSuccess(): void
    {
        $user = $this->createMockUser();
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())->setAccount($account)->setNickname('Player');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $denormalizer = $this->createMock(DenormalizerInterface::class);
        $denormalizer->expects($this->once())->method('denormalize');

        $controller = new ApiGameProfilesController($em, $this->createStub(ServiceLocator::class), sys_get_temp_dir());
        $this->attachMockContainer($controller);

        $req = Request::create(
            '/api/accounts/1/gameProfiles/1/settings',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['skport_check_in' => false])
        );

        $response = $controller->apiGameProfilesUpdateSettings($req, $account, $profile, $denormalizer);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testApiGameProfilesUpdateSettingsChecksProfileBelongsToAccount(): void
    {
        $user = $this->createMockUser();
        $account1 = (new GryphlineAccount())->setUser($user);
        $account2 = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())->setAccount($account2)->setNickname('Player');

        $em = $this->createStub(EntityManagerInterface::class);
        $denormalizer = $this->createStub(DenormalizerInterface::class);

        $controller = new ApiGameProfilesController($em, $this->createStub(ServiceLocator::class), sys_get_temp_dir());
        $this->attachMockContainer($controller);

        $req = Request::create(
            '/api/accounts/1/gameProfiles/1/settings',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['skport_check_in' => false])
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Game profile does not belong to this account.');

        $controller->apiGameProfilesUpdateSettings($req, $account1, $profile, $denormalizer);
    }
}
