<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ends the session of a user whose account was disabled after logging in: the firewall reloads the user from the
 * database at each request, but it checks the account (UserChecker) only when logging in.
 *
 * Right after the firewall (priority 8). The request goes on without a user, so the access checks answer as for
 * anyone logged out: 401 from the API, the login page for the pages. The remember-me logins of the user were deleted
 * when the account was disabled, and UserChecker refuses a cookie left in the browser.
 *
 * Reading the token loads the session user at every request, also on the routes without access checks: "lazy" of
 * the main firewall saves nothing any more. Every page of the site needs a login anyway.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
class DisabledUserLogoutListener
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || !$user->isDisabled()) {
            return;
        }

        $this->tokenStorage->setToken(null);
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
    }
}
