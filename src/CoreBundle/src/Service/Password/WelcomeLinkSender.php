<?php

namespace App\CoreBundle\Service\Password;

use App\CoreBundle\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Welcome email of a user created by an admin: a link to choose the password, on the reset password page. It is a
 * reset link with its own lifetime, longer than the "forgot password" ones (reset_password.yaml): a new user may not
 * read the email at once. After it expires, "Forgot password" sends a new link as for anyone else.
 */
class WelcomeLinkSender
{
    public const int LINK_LIFETIME = 7 * 24 * 3600;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * For a user just saved (the link is stored with its id) and without other links: the throttle of the reset
     * links (one per throttle_limit) would refuse a second one.
     *
     * @param bool $passwordSet whether the admin chose a password, which the email never contains
     */
    public function send(User $user, bool $passwordSet): void
    {
        $token = $this->resetPasswordHelper->generateResetToken($user, self::LINK_LIFETIME);

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail()))
            ->subject('Your account is ready')
            ->htmlTemplate('@Core/email/welcome.html.twig')
            ->context([
                // "email" is reserved by TemplatedEmail
                'account_email' => $user->getEmail(),
                // Built here, during the request: the worker that renders the email has no host to link to
                'set_password_url' => $this->urlGenerator->generate('core_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                'expires_in' => self::getLinkLifetime(),
                'password_set' => $passwordSet,
                'login_url' => $this->urlGenerator->generate('core_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'forgot_password_url' => $this->urlGenerator->generate('core_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    /**
     * How long the link works, as the email and the admin page say it.
     */
    public static function getLinkLifetime(): string
    {
        return intdiv(self::LINK_LIFETIME, 24 * 3600).' days';
    }
}
