<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\SubmitCaptchaRequest;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GamesBundle\Service\GeetestService;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\GryphlineBundle\Model\Auth\StartLoginRequest;
use App\GryphlineBundle\Service\GryphlineAuthService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[Route('/accounts', name: 'api_gryphline_accounts_auth_', format: 'json')]
class ApiAccountAuthController extends AbstractController
{
    public function __construct(
        private readonly GryphlineAuthService $authService,
        private readonly GeetestService $geetestService,
    ) {
    }

    #[Route('/login/start', name: 'login_start', methods: ['POST'])]
    public function loginStart(
        #[CurrentUser] User $user,
        #[MapRequestPayload] StartLoginRequest $payload,
    ): Response {
        $result = $this->authService->startLogin(
            user: $user,
            email: $payload->getEmail(),
            password: $payload->getPassword(),
        );

        return $this->jsonStepResult($result);
    }

    #[Route('/{account}/relogin/start', name: 'relogin_start', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function reloginStart(
        #[CurrentUser] User $user,
        #[MapEntity(message: 'Account not found.')] GryphlineAccount $account,
        #[MapRequestPayload] StartLoginRequest $payload,
    ): Response {
        $result = $this->authService->startLogin(
            user: $user,
            email: $payload->getEmail(),
            password: $payload->getPassword(),
            reloginAccount: $account,
        );

        return $this->jsonStepResult($result);
    }

    #[Route('/login/captcha', name: 'login_captcha', methods: ['POST'])]
    public function loginCaptcha(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SubmitCaptchaRequest $payload,
    ): Response {
        $result = $this->authService->submitCaptcha(
            user: $user,
            loginSessionId: $payload->getLoginSessionId(),
            geetestPayload: $payload->getCaptcha(),
        );

        return $this->jsonStepResult($result);
    }

    #[Route('/login/geetest-frame', name: 'geetest_frame', methods: ['GET'], format: 'html')]
    public function geetestFrame(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $loginSessionId = trim($request->query->getString('login_session_id'));
        if ($loginSessionId === '') {
            throw new BadRequestHttpException('Missing login_session_id parameter.');
        }

        $captchaConfig = $this->authService->getSessionCaptchaConfig($user, $loginSessionId);

        return $this->geetestService->createFrameResponse($captchaConfig, 4);
    }

    private function jsonStepResult(LoginStepResult $result): Response
    {
        return $this->json($result, Response::HTTP_OK, context: [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['gameProfiles'],
        ]);
    }
}
