<?php

namespace App\CoreBundle\Service\Password;

use App\CoreBundle\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * "Forgot password": sends a reset link to the account with the given email. The caller cannot tell whether the
 * account exists, so the request page answers the same way for any address.
 */
class PasswordResetLinkSender
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function send(string $email): void
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException) {
            // A link was sent less than throttle_limit ago (reset_password.yaml), and it still works
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail()))
            ->subject('Reset your password')
            ->htmlTemplate('@Core/email/reset_password_link.html.twig')
            ->context([
                // Built here, during the request: the link opens on the host the user asked from
                'reset_url' => $this->urlGenerator->generate('core_reset_password', ['token' => $resetToken->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                'expires_in' => $this->getLinkLifetime(),
            ]));
    }

    /**
     * How long a link works, as the email and the "check your email" page say it, e.g. "1 hour".
     */
    public function getLinkLifetime(): string
    {
        $minutes = intdiv($this->resetPasswordHelper->getTokenLifetime(), 60);
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $minutes === 1 ? '1 minute' : $minutes.' minutes';
    }
}
