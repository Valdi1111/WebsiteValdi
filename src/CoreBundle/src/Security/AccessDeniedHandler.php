<?php

namespace App\CoreBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

readonly class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private Security $security,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        // JSON also without a session (the stateless "api_token" firewall): the redirect below needs one for its flash
        if ($request->getRequestFormat() === 'json' || $this->security->getFirewallConfig($request)?->isStateless()) {
            return new JsonResponse([
                'type' => 'https://datatracker.ietf.org/doc/html/rfc2616#section-10',
                'title' => 'Authorization error',
                'status' => 403,
                'detail' => $accessDeniedException->getMessage(),
            ], 403);
        }
        // add a custom flash message and redirect to the login page
        $request->getSession()->getFlashBag()->add('note', "You lack the permissions to access this page.");
        return new RedirectResponse($this->urlGenerator->generate('core_login'));
    }
}