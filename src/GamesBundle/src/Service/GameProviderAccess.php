<?php

namespace App\GamesBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves which game providers (tabs) the current user can access, based on the
 * provider => role map configured under `games.providers`.
 */
class GameProviderAccess
{
    /**
     * @param array<string, string> $providers provider key => role, in tab order
     */
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        #[Autowire(param: 'games.providers')]
        private readonly array $providers,
    ) {
    }

    /**
     * @return list<string> provider keys granted to the current user, in configured order
     */
    public function getGrantedProviders(): array
    {
        $granted = [];
        foreach ($this->providers as $key => $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                $granted[] = (string) $key;
            }
        }

        return $granted;
    }
}
