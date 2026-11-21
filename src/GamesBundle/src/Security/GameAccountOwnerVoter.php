<?php

namespace App\GamesBundle\Security;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access to a game account of any provider only to the user who owns it.
 *
 * Used as #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')] on every route with an {account} parameter.
 * Users are compared by id, so a different instance of the same user (e.g. reloaded by the security layer) still matches.
 *
 * @extends Voter<string, GameAccountInterface>
 */
class GameAccountOwnerVoter extends Voter
{
    public const string OWNER = 'GAME_ACCOUNT_OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::OWNER && $subject instanceof GameAccountInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof GameAccountInterface) {
            return false;
        }

        $owner = $subject->getUser();
        if ($owner === null || $owner->getId() === null || $owner->getId() !== $user->getId()) {
            $vote?->addReason('The game account belongs to another user.');

            return false;
        }

        return true;
    }
}
