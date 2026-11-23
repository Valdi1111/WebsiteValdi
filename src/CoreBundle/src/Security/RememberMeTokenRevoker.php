<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * Remember-me logins of a user: the persistent tokens of the firewall (security.yaml, table rememberme_token).
 *
 * Since Symfony 8.1 a persistent token is bound to the password hash (remember_me.signature_properties, "password"
 * by default): after a password change every cookie issued before stops logging in, also the one of the current
 * device. The rows are deleted anyway: tokens issued before 8.1 carry no digest and would still log in, and the
 * others would stay in the table until they expire.
 */
class RememberMeTokenRevoker
{
    /** Default name of the remember-me cookie, which security.yaml does not change */
    private const string COOKIE_NAME = 'REMEMBERME';

    public function __construct(
        private readonly Connection $connection,
        private readonly RememberMeHandlerInterface $rememberMeHandler,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Deletes every remember-me login of the user. With $keepCurrentDevice, the device of the current request, if it
     * was remembered, gets a new cookie bound to the new password, written on the response.
     */
    public function revoke(User $user, bool $keepCurrentDevice): void
    {
        $this->connection->delete('rememberme_token', ['username' => $user->getUserIdentifier()]);

        if ($keepCurrentDevice && $this->requestStack->getMainRequest()?->cookies->has(self::COOKIE_NAME)) {
            $this->rememberMeHandler->createRememberMeCookie($user);
        }
    }
}
