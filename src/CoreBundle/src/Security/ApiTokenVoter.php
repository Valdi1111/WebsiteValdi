<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What a login with an API token must not reach. Granted only to a login of the user (form or remember-me):
 * - MANAGE: listing, showing, creating, changing and deleting the user's API tokens: the values can be shown, so a
 *   leaked token would give away the others and could make new ones;
 * - ADMIN_USERS: the users admin API (creating and disabling users): a leaked token of an admin could create
 *   another admin account. Checked together with ROLE_ADMIN.
 *
 * @extends Voter<string, null>
 */
class ApiTokenVoter extends Voter
{
    public const string MANAGE = 'CORE_API_TOKENS_MANAGE';
    public const string ADMIN_USERS = 'CORE_ADMIN_USERS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE || $attribute === self::ADMIN_USERS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }

        if ($token->hasAttribute(AccessTokenHandler::TOKEN_ID_ATTRIBUTE)) {
            $vote?->addReason('Logged in with an API token.');

            return false;
        }

        return true;
    }
}
