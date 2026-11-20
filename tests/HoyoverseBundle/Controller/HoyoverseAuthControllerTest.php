<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\SubmitCaptchaRequest;
use App\GamesBundle\Service\GeetestService;
use App\HoyoverseBundle\Controller\ApiAccountAuthController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\HoyoverseBundle\Model\Auth\StartLoginRequest;
use App\HoyoverseBundle\Model\Auth\VerifyEmailRequest;
use App\HoyoverseBundle\Service\HoyoverseAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\SerializerInterface;

class HoyoverseAuthControllerTest extends TestCase
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

    public function testLoginStartReturnsJsonStepResult(): void
    {
        $user = $this->createMockUser(1);
        $authService = $this->createMock(HoyoverseAuthService::class);
        $geetestService = $this->createStub(GeetestService::class);

        $authService->expects($this->once())
            ->method('startLogin')
            ->with($user, 'player@example.com', 'pwd123', null)
            ->willReturn(new LoginStepResult(LoginStepResult::STATUS_AUTHENTICATED));

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $payload = (new StartLoginRequest())
            ->setAccount('player@example.com')
            ->setPassword('pwd123');

        $response = $controller->loginStart($user, $payload);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testReloginStartChecksAccountOwnership(): void
    {
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);
        $accountOfUser2 = (new HoyoverseAccount())->setUser($user2);

        $authService = $this->createStub(HoyoverseAuthService::class);
        $geetestService = $this->createStub(GeetestService::class);

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $payload = (new StartLoginRequest())->setAccount('a@b.c')->setPassword('pwd');

        $this->expectException(AccessDeniedException::class);
        $controller->reloginStart($user1, $accountOfUser2, $payload);
    }

    public function testLoginCaptchaAndVerifyEmailDelegateToService(): void
    {
        $user = $this->createMockUser(1);
        $authService = $this->createMock(HoyoverseAuthService::class);
        $geetestService = $this->createStub(GeetestService::class);

        $authService->expects($this->once())
            ->method('submitCaptcha')
            ->with($user, 'sess-123', ['lot_number' => '1'])
            ->willReturn(new LoginStepResult(LoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED, 'sess-123'));

        $authService->expects($this->once())
            ->method('verifyEmail')
            ->with($user, 'sess-123', '654321')
            ->willReturn(new LoginStepResult(LoginStepResult::STATUS_AUTHENTICATED));

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $captchaReq = (new SubmitCaptchaRequest())
            ->setLoginSessionId('sess-123')
            ->setCaptcha(['lot_number' => '1']);
        $res1 = $controller->loginCaptcha($user, $captchaReq);
        $this->assertSame(Response::HTTP_OK, $res1->getStatusCode());

        $verifyReq = (new VerifyEmailRequest())
            ->setLoginSessionId('sess-123')
            ->setCode('654321');
        $res2 = $controller->loginVerifyEmail($user, $verifyReq);
        $this->assertSame(Response::HTTP_OK, $res2->getStatusCode());
    }

    public function testGeetestFrameReturnsNoReferrerHtml(): void
    {
        $user = $this->createMockUser(1);
        $authService = $this->createMock(HoyoverseAuthService::class);
        $geetestService = $this->createMock(GeetestService::class);

        $authService->expects($this->once())
            ->method('getSessionCaptchaConfig')
            ->with($user, 'sess-xyz')
            ->willReturn(['version' => 4, 'captcha_id' => 'gt4']);

        $geetestService->expects($this->once())
            ->method('createFrameResponse')
            ->with($this->isArray(), 3)
            ->willReturn(new Response('<html>captcha</html>', Response::HTTP_OK, ['Referrer-Policy' => 'no-referrer']));

        $controller = new ApiAccountAuthController($authService, $geetestService);
        $this->attachMockContainer($controller);

        $req = Request::create('/api/accounts/login/geetest-frame', 'GET', ['login_session_id' => 'sess-xyz']);
        $response = $controller->geetestFrame($req, $user);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('<html>captcha</html>', $response->getContent());
    }
}
