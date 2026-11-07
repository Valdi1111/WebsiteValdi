<?php

namespace App\Tests\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GryphlineBundle\Controller\ApiAccountsController;
use App\GryphlineBundle\Controller\ApiGameProfilesController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\SkportManagerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

    private function attachMockContainer(object $controller): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('{}');

        $container = new Container();
        $container->set('serializer', $serializer);

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

    public function testApiAccountsAddSuccess(): void
    {
        $user = $this->createMockUser();
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(GryphlineAccountRepository::class);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $req = Request::create(
            '/api/accounts',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['account_token' => 'sample-test-token'])
        );

        $controller = new ApiAccountsController($em, $repo);
        $this->attachMockContainer($controller);

        $response = $controller->apiAccountsAdd($req, $user);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testApiAccountsAddThrowsWhenTokenEmpty(): void
    {
        $user = $this->createMockUser();
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(GryphlineAccountRepository::class);

        $req = Request::create(
            '/api/accounts',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['account_token' => ''])
        );

        $controller = new ApiAccountsController($em, $repo);
        $this->attachMockContainer($controller);

        $this->expectException(BadRequestHttpException::class);
        $controller->apiAccountsAdd($req, $user);
    }

    public function testApiAccountsSyncChecksOwnership(): void
    {
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);

        $account = (new GryphlineAccount())->setUser($user2);
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(GryphlineAccountRepository::class);
        $manager = $this->createStub(SkportManagerService::class);

        $controller = new ApiAccountsController($em, $repo);
        $this->attachMockContainer($controller);

        $this->expectException(AccessDeniedException::class);
        $controller->apiAccountsSync($user1, $account, $manager);
    }

    public function testApiAccountsDeleteChecksOwnership(): void
    {
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);

        $account = (new GryphlineAccount())->setUser($user2);
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(GryphlineAccountRepository::class);

        $controller = new ApiAccountsController($em, $repo);
        $this->attachMockContainer($controller);

        $this->expectException(AccessDeniedException::class);
        $controller->apiAccountsDelete($user1, $account);
    }

    public function testApiGameProfilesGet(): void
    {
        $user = $this->createMockUser();
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())->setAccount($account)->setNickname('Player');
        $account->addGameProfile($profile);

        $em = $this->createStub(EntityManagerInterface::class);
        $controller = new ApiGameProfilesController($em);
        $controller->setAccount($account);
        $this->attachMockContainer($controller);

        $response = $controller->apiGameProfilesGet($user);
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

        $controller = new ApiGameProfilesController($em);
        $controller->setAccount($account);
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

        $response = $controller->apiGameProfilesUpdateSettings($req, $user, $profile, $denormalizer);
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

        $controller = new ApiGameProfilesController($em);
        $controller->setAccount($account1);
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

        $controller->apiGameProfilesUpdateSettings($req, $user, $profile, $denormalizer);
    }
}
