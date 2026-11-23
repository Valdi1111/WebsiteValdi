<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Form\ResetPasswordFormType;
use App\CoreBundle\Form\ResetPasswordRequestFormType;
use App\CoreBundle\Service\Password\PasswordResetLinkSender;
use App\CoreBundle\Service\Password\UserPasswordManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * "Forgot password": the user asks for a link by email, then chooses a new password from it.
 * Public pages, like the login one, on every subdomain (core routes have no host), above the "/{path}" catch-all of
 * the bundle apps.
 */
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public const string FLASH_REQUEST_ERROR = 'reset_password_error';
    public const string FLASH_LOGIN_NOTICE = 'login_notice';

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly PasswordResetLinkSender $linkSender,
        private readonly UserPasswordManager $passwordManager,
    ) {
    }

    #[Route('/forgot-password', name: 'forgot_password_request', methods: ['GET', 'POST'], priority: 1000)]
    #[RateLimit('core_password_reset_request', methods: ['POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->linkSender->send($form->get('email')->getData());

            // The same answer whether an account has this email or not
            return $this->redirectToRoute('core_forgot_password_check_email');
        }

        return $this->render('@Core/security/forgot_password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/forgot-password/check-email', name: 'forgot_password_check_email', methods: ['GET'], priority: 1000)]
    public function checkEmail(): Response
    {
        return $this->render('@Core/security/check_email.html.twig', [
            'expires_in' => $this->linkSender->getLinkLifetime(),
        ]);
    }

    #[Route('/reset-password/{token}', name: 'reset_password', methods: ['GET', 'POST'], priority: 1000)]
    public function reset(Request $request, ?string $token = null): Response
    {
        if ($token !== null) {
            // The token moves from the URL to the session, so that the page and its requests do not leak it
            // (JavaScript, Referer header)
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('core_reset_password');
        }

        $token = $this->getTokenFromSession();
        if ($token === null) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash(self::FLASH_REQUEST_ERROR, $e->getReason());

            return $this->redirectToRoute('core_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Also deletes the link, and every other one of the user
            $this->passwordManager->reset($user, $form->get('plainPassword')->getData());
            $this->cleanSessionAfterReset();
            $this->addFlash(self::FLASH_LOGIN_NOTICE, 'Your password has been changed: log in with the new one.');

            return $this->redirectToRoute('core_login');
        }

        return $this->render('@Core/security/reset_password.html.twig', [
            'form' => $form,
            'email' => $user->getEmail(),
        ]);
    }
}
