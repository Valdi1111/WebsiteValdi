<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * MANAGE: listing, showing, creating, changing and deleting the user's API tokens. Only from a login of the user
 * (form or remember-me), never with an API token: the values can be shown, so a leaked token would give away the
 * others and could make new ones.
 *
 * @extends Voter<string, null>
 */
class ApiTokenVoter extends Voter
{
    public const string MANAGE = 'CORE_API_TOKENS_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE;
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
