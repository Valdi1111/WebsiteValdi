<?php

namespace App\CoreBundle\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Marks the security token of a login made with an API token (AccessTokenHandler), with the id of the API token.
 * Such logins happen on the stateless "api_token" firewall, at every request: the mark never reaches a session.
 *
 * Global listeners of security events also run on the dispatcher of each firewall.
 */
#[AsEventListener]
class ApiTokenAuthenticationListener
{
    public function __invoke(AuthenticationTokenCreatedEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(UserBadge::class)) {
            return;
        }

        $attributes = $passport->getBadge(UserBadge::class)->getAttributes() ?? [];
        if (isset($attributes[AccessTokenHandler::TOKEN_ID_ATTRIBUTE])) {
            $event->getAuthenticatedToken()->setAttribute(AccessTokenHandler::TOKEN_ID_ATTRIBUTE, $attributes[AccessTokenHandler::TOKEN_ID_ATTRIBUTE]);
        }
    }
}
