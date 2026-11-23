<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Repository\TokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Logs in with an API token ("Authorization: Bearer <token>"), found by its hash: the encrypted value is never read,
 * so the login does not depend on DATABASE_ENCRYPTION_KEY.
 *
 * The badge carries the id of the token, which ApiTokenAuthenticationListener copies to the security token: the
 * pages that must not be reached with an API token (ApiTokenVoter) look for it.
 */
readonly class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public const string TOKEN_ID_ATTRIBUTE = 'core_api_token_id';

    public function __construct(private TokenRepository $tokenRepo)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $credentials = $this->tokenRepo->findCredentialsByAccessToken($accessToken);
        if ($credentials === null || !$credentials['valid']) {
            throw new BadCredentialsException('Invalid token.');
        }

        // The user provider of the firewall loads the user by its identifier, the email
        return new UserBadge($credentials['username'], attributes: [self::TOKEN_ID_ATTRIBUTE => $credentials['id']]);
    }
}
