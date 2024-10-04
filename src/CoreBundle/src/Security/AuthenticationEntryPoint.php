<?php

namespace App\CoreBundle\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

readonly class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @inheritDoc
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($request->getRequestFormat() === 'json') {
            return new JsonResponse([
                'type' => 'https://datatracker.ietf.org/doc/html/rfc2616#section-10',
                'title' => 'Authentication error',
                'status' => 401,
                'detail' => $authException->getMessage(),
            ], 401);
        }
        // add a custom flash message and redirect to the login page
        $request->getSession()->getFlashBag()->add('note', "You have to login in order to access this page.");
        return new RedirectResponse($this->urlGenerator->generate('core_login'));
    }
}