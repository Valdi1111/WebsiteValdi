<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Repository\TokenRepository;
use App\CoreBundle\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class AccessTokenHandler implements AccessTokenHandlerInterface
{

    public function __construct(private readonly TokenRepository $tokenRepo, private readonly UserRepository $userRepo)
    {
    }


    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokenRepo->findOneBy(['access_token' => $accessToken]);
        if (!$token || !$token->isValid()) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        $user = $this->userRepo->findOneBy(['id' => $token->getId()]);
        return new UserBadge($user->getUserIdentifier());
    }

}