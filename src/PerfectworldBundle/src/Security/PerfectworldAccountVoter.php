<?php

namespace App\PerfectworldBundle\Security;

use App\CoreBundle\Entity\User;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access to a Perfect World account only to the user who owns it.
 *
 * @extends Voter<string, PerfectworldAccount>
 */
class PerfectworldAccountVoter extends Voter
{
    public const string OWNER = 'PERFECTWORLD_ACCOUNT_OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::OWNER && $subject instanceof PerfectworldAccount;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof PerfectworldAccount) {
            return false;
        }

        $owner = $subject->getUser();
        if ($owner === null || $owner->getId() !== $user->getId()) {
            $vote?->addReason('The Perfect World account belongs to another user.');

            return false;
        }

        return true;
    }
}
