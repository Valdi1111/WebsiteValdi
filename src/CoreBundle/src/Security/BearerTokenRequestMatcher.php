<?php

namespace App\CoreBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Security\Http\AccessToken\HeaderAccessTokenExtractor;

/**
 * Requests of the "api_token" firewall (security.yaml): the ones carrying an API token, "Authorization: Bearer
 * <token>", read as the access token authenticator reads them. Every other request, also with another kind of
 * Authorization header, goes to the "main" firewall.
 */
class BearerTokenRequestMatcher implements RequestMatcherInterface
{
    private readonly HeaderAccessTokenExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new HeaderAccessTokenExtractor();
    }

    public function matches(Request $request): bool
    {
        return $this->extractor->extractAccessToken($request) !== null;
    }
}
