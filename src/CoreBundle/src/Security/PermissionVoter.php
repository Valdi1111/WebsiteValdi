<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Service\PermissionHierarchyInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PermissionVoter implements CacheableVoterInterface
{
    public function __construct(
        private PermissionHierarchyInterface $permissionHierarchy
    ) {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes/* , ?Vote $vote = null */): int
    {
        $vote = 3 < func_num_args() ? func_get_arg(3) : null;
        $result = VoterInterface::ACCESS_ABSTAIN;
        $permissions = $this->extractPermissions($token);
        $missingPermissions = [];

        foreach ($attributes as $attribute) {
            if ($attribute !== 'permission') {
                continue;
            }
            $result = VoterInterface::ACCESS_DENIED;

            if (in_array($subject, $permissions, true)) {
                $vote?->addReason(sprintf('The user has %s.', $subject));
                return VoterInterface::ACCESS_GRANTED;
            }

            $missingPermissions[] = $subject;
        }

        if (VoterInterface::ACCESS_DENIED === $result) {
            $vote?->addReason(sprintf('The user doesn\'t have%s %s.', 1 < count($missingPermissions) ? ' any of' : '', implode(', ', $missingPermissions)));
        }

        return $result;
    }

    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === 'permission';
    }

    public function supportsType(string $subjectType): bool
    {
        return $subjectType === 'string';
    }

    protected function extractPermissions(TokenInterface $token): array
    {
        return $this->permissionHierarchy->getReachablePermissionNames($token->getRoleNames());
    }
}