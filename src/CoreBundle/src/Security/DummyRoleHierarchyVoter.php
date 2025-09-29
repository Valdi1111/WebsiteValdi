<?php

namespace App\CoreBundle\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AsDecorator('security.access.role_hierarchy_voter', priority: 100, onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
class DummyRoleHierarchyVoter implements CacheableVoterInterface
{
    public function supportsAttribute(string $attribute): bool
    {
        return false;
    }

    public function supportsType(string $subjectType): bool
    {
        return false;
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        return VoterInterface::ACCESS_ABSTAIN;
    }
}