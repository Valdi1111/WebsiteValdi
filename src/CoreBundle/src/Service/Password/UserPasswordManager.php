<?php

namespace App\CoreBundle\Service\Password;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\InvalidCurrentPasswordException;
use App\CoreBundle\Exception\TooManyPasswordAttemptsException;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every password change goes through here: from the profile page, knowing the current password, and from a reset
 * link. The new password is hashed with the "auto" hasher (security.yaml), then the other ways to stay logged in with
 * the old one are closed and the user is told by email:
 * - sessions: nothing to do, ContextListener::hasUserChanged() logs out every session whose user has another password
 *   hash; the session of the current request stores the user with the new hash, so it stays logged in;
 * - remember-me logins: RememberMeTokenRevoker;
 * - pending reset links: deleted, a link sent before the change must not work after it.
 * The API access tokens (Token) are kept on purpose: they do not depend on the password.
 */
class UserPasswordManager
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordRequestRepository $resetPasswordRequests,
        private readonly RememberMeTokenRevoker $rememberMeTokens,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Target('core_password_change')]
        private readonly RateLimiterFactoryInterface $changeLimiter,
    ) {
    }

    /**
     * Change from the profile page: the current device stays logged in, also through its remember-me cookie.
     *
     * @throws TooManyPasswordAttemptsException when the user tried too many current passwords
     * @throws InvalidCurrentPasswordException
     */
    public function change(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): void
    {
        // Consumed before the check, so that wrong attempts count and a blocked user learns nothing about the password
        $limiter = $this->changeLimiter->create((string) $user->getId());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyPasswordAttemptsException($limit->getRetryAfter());
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new InvalidCurrentPasswordException();
        }
        $limiter->reset();

        $this->update($user, $newPassword, true);
    }

    /**
     * Change from a reset link, already validated by the caller: no device stays logged in.
     */
    public function reset(User $user, #[\SensitiveParameter] string $newPassword): void
    {
        $this->update($user, $newPassword, false);
    }

    private function update(User $user, #[\SensitiveParameter] string $newPassword, bool $keepCurrentDevice): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        $this->resetPasswordRequests->removeRequests($user);
        $this->rememberMeTokens->revoke($user, $keepCurrentDevice);

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail()))
            ->subject('Your password has been changed')
            ->htmlTemplate('@Core/email/password_changed.html.twig')
            ->context([
                'changed_at' => new \DateTimeImmutable(),
                // Built here, during the request: the worker that renders the email has no host to link to
                'forgot_password_url' => $this->urlGenerator->generate('core_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }
}
