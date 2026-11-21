<?php

namespace App\PerfectworldBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Model\Auth\ResendEmailRequest;
use App\PerfectworldBundle\Model\Auth\StartLoginRequest;
use App\PerfectworldBundle\Model\Auth\VerifyEmailRequest;
use App\PerfectworldBundle\Security\PerfectworldAccountVoter;
use App\PerfectworldBundle\Service\PerfectworldAuthService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Email + code login wizard (no captcha, no password).
 */
#[IsGranted('ROLE_USER_PERFECTWORLD', null, 'Access Denied.')]
#[Route('/accounts', name: 'api_perfectworld_accounts_auth_', format: 'json')]
class ApiAccountAuthController extends AbstractController
{
    public function __construct(
        private readonly PerfectworldAuthService $authService,
    ) {
    }

    #[Route('/login/start', name: 'login_start', methods: ['POST'])]
    public function loginStart(
        #[CurrentUser] User $user,
        #[MapRequestPayload] StartLoginRequest $payload,
    ): Response {
        return $this->jsonStepResult($this->authService->startLogin($user, $payload->getEmail()));
    }

    #[Route('/{account}/relogin/start', name: 'relogin_start', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(PerfectworldAccountVoter::OWNER, subject: 'account')]
    public function reloginStart(
        #[CurrentUser] User $user,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapRequestPayload] StartLoginRequest $payload,
    ): Response {
        return $this->jsonStepResult($this->authService->startLogin($user, $payload->getEmail(), $account));
    }

    #[Route('/login/verify-email', name: 'login_verify_email', methods: ['POST'])]
    public function loginVerifyEmail(
        #[CurrentUser] User $user,
        #[MapRequestPayload] VerifyEmailRequest $payload,
    ): Response {
        return $this->jsonStepResult($this->authService->verifyCode($user, $payload->getLoginSessionId(), $payload->getCode()));
    }

    #[Route('/login/resend-email', name: 'login_resend_email', methods: ['POST'])]
    public function loginResendEmail(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ResendEmailRequest $payload,
    ): Response {
        return $this->jsonStepResult($this->authService->resendCode($user, $payload->getLoginSessionId()));
    }

    private function jsonStepResult(LoginStepResult $result): Response
    {
        return $this->json($result, Response::HTTP_OK, context: [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['gameProfiles'],
        ]);
    }
}
