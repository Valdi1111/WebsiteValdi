<?php

namespace App\Tests\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\SubmitCaptchaRequest;
use App\GamesBundle\Service\GeetestService;
use App\GryphlineBundle\Controller\ApiAccountAuthController;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Model\Auth\GryphlineLoginStepResult;
use App\GryphlineBundle\Model\Auth\StartLoginRequest;
use App\GryphlineBundle\Service\GryphlineAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\SerializerInterface;

class GryphlineAuthControllerTest extends TestCase
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
        $serializer->method('serialize')->willReturn('{"status":"authenticated"}');

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/api/accounts/login/geetest-script/v4');

        $container = new Container();
        $container->set('serializer', $serializer);
        $container->set('router', $router);

        if (method_exists($controller, 'setContainer')) {
            $controller->setContainer($container);
        }
    }

    public function testLoginStartAndReloginOwnership(): void
    {
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);
        $accountOfUser2 = (new GryphlineAccount())->setUser($user2);

        $authService = $this->createMock(GryphlineAuthService::class);
        $geetestService = $this->createStub(GeetestService::class);

        $authService->expects($this->once())
            ->method('startLogin')
            ->with($user1, 'doctor@gryphline.com', 'pwd123', null)
            ->willReturn(new GryphlineLoginStepResult(GryphlineLoginStepResult::STATUS_AUTHENTICATED));

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $payload = (new StartLoginRequest())
            ->setEmail('doctor@gryphline.com')
            ->setPassword('pwd123');

        $res = $controller->loginStart($user1, $payload);
        $this->assertSame(Response::HTTP_OK, $res->getStatusCode());

        $this->expectException(AccessDeniedException::class);
        $controller->reloginStart($user1, $accountOfUser2, $payload);
    }

    public function testLoginCaptchaAndGeetestFrame(): void
    {
        $user = $this->createMockUser(1);
        $authService = $this->createMock(GryphlineAuthService::class);
        $geetestService = $this->createMock(GeetestService::class);

        $authService->expects($this->once())
            ->method('submitCaptcha')
            ->with($user, 'sess-gryph-1', ['lot_number' => '99'])
            ->willReturn(new GryphlineLoginStepResult(GryphlineLoginStepResult::STATUS_AUTHENTICATED));

        $authService->expects($this->once())
            ->method('getSessionCaptchaConfig')
            ->with($user, 'sess-gryph-1')
            ->willReturn(['version' => 4, 'captcha_id' => 'gt4-id']);

        $geetestService->expects($this->once())
            ->method('renderFrameHtml')
            ->willReturn('<html>gryphline-captcha</html>');

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $captchaReq = (new SubmitCaptchaRequest())
            ->setLoginSessionId('sess-gryph-1')
            ->setCaptcha(['lot_number' => '99']);
        $res1 = $controller->loginCaptcha($user, $captchaReq);
        $this->assertSame(Response::HTTP_OK, $res1->getStatusCode());

        $req = Request::create('/api/accounts/login/geetest-frame', 'GET', ['login_session_id' => 'sess-gryph-1']);
        $res2 = $controller->geetestFrame($req, $user);
        $this->assertSame(Response::HTTP_OK, $res2->getStatusCode());
        $this->assertSame('no-referrer', $res2->headers->get('Referrer-Policy'));
    }
}
