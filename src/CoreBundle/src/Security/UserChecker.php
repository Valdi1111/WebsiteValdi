<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses the logins of a disabled account (User::$disabledAt): with the form, a remember-me cookie or an API token
 * (user_checker of the main and api_token firewalls in security.yaml). The sessions opened before the account was
 * disabled end in DisabledUserLogoutListener.
 *
 * Checked after the credentials, so that the login form tells that an account is disabled only to whoever knows its
 * password.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $user->isDisabled()) {
            throw new CustomUserMessageAccountStatusException('Your account has been disabled.');
        }
    }
}
