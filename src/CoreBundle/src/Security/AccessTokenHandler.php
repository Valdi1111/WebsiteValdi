<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Repository\TokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

readonly class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private TokenRepository $tokenRepo)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokenRepo->findOneBy(['accessToken' => $accessToken]);
        if (!$token || !$token->isValid()) {
            throw new BadCredentialsException('Invalid token.');
        }
        $user = $this->tokenRepo->getUserFromToken($token);
        return new UserBadge($user->getUserIdentifier());
    }
}